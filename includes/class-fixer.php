<?php
/**
 * Applies an issue's automatic fix to the plugin that owns the rule.
 *
 * @package Onehop\RedirectChecker
 */

namespace Onehop\RedirectChecker\Includes;

use Onehop\RedirectChecker\Includes\Analysis\Analyzer;
use Onehop\RedirectChecker\Includes\Sources\Registry;
use Onehop\RedirectChecker\Includes\Sources\Writable;

defined( 'ABSPATH' ) || exit;

/**
 * Every rule is re-read and compared with what the scan saw before anything is written: a rule
 * that changed (or disappeared) since the scan refuses the whole fix. Each change is logged.
 */
final class Fixer {

	/**
	 * One write at a time: fixes, migrations and deletions share this lock (also used by Migrator).
	 */
	const LOCK = 'onehop_redirect_checker_write_lock';

	/**
	 * Apply the fix of an issue.
	 *
	 * @param int $issue_id Issue ID.
	 * @param int $keep     Loop fix (break): index in the fix's rules of the rule whose source URL
	 *                      becomes the page that loads. Ignored by the other fixes.
	 *
	 * @return array{action:string, changes:array[]} What was done.
	 *
	 * @throws \RuntimeException When there is nothing to fix, the rules changed, or a write failed.
	 */
	public static function apply( int $issue_id, int $keep = 0 ): array {
		if ( Scanner::is_running() || ! Lock::acquire( self::LOCK, 2 * MINUTE_IN_SECONDS ) ) {
			throw new \RuntimeException( __( 'Another change or a scan is running. Try again in a moment.', 'onehop-redirect-checker' ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		try {
			return self::apply_locked( $issue_id, $keep );
		} finally {
			Lock::release( self::LOCK );
		}
	}

	/**
	 * Apply the fix while holding the write lock (the "already applied" check included).
	 *
	 * @param int $issue_id Issue ID.
	 * @param int $keep     See apply().
	 *
	 * @return array{action:string, changes:array[]}
	 *
	 * @throws \RuntimeException See apply().
	 */
	private static function apply_locked( int $issue_id, int $keep ): array {
		$issue = Log_Repository::issue( $issue_id );
		$fix   = $issue['fix'] ?? null;

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are shown as text (JSON/React).
		if ( ! $issue || ! is_array( $fix ) || empty( $fix['rules'] ) || ! in_array( $fix['action'] ?? '', [ 'retarget', 'disable', 'break' ], true ) ) {
			throw new \RuntimeException( __( 'This issue has no automatic fix.', 'onehop-redirect-checker' ), 400 );
		}

		// Only a URL that nothing else redirects once its rule is off (Analyzer::loop_fix()).
		if ( 'break' === $fix['action'] && ( ! isset( $fix['rules'][ $keep ] ) || ! in_array( $keep, (array) ( $fix['candidates'] ?? [] ), true ) ) ) {
			throw new \RuntimeException( __( 'Choose the URL that should load.', 'onehop-redirect-checker' ), 400 );
		}

		if ( ! empty( $issue['fixed'] ) ) {
			throw new \RuntimeException( __( 'This fix was already applied.', 'onehop-redirect-checker' ), 409 );
		}

		$sources = Registry::all();
		$targets = [];

		// Check everything first, so a stale scan changes nothing.
		foreach ( $fix['rules'] as $ref ) {
			$source = $sources[ $ref['source'] ?? '' ] ?? null;
			if ( ! $source instanceof Writable || ! $source->is_available() ) {
				throw new \RuntimeException( __( 'The plugin that owns this rule is not active.', 'onehop-redirect-checker' ), 409 );
			}

			$current = $source->find( (string) $ref['id'] );
			if ( ! $current || $current->from !== $ref['from'] || $current->to !== $ref['to'] ) {
				throw new \RuntimeException( __( 'The rule changed since the last scan. Run a new scan first.', 'onehop-redirect-checker' ), 409 );
			}

			$targets[] = [ $source, $current ];
		}

		// Rules the fix relies on without changing them (the later hops of a chain, the duplicate
		// that stays) must still be as scanned, where their plugin lets us read one rule back.
		foreach ( (array) ( $fix['requires'] ?? [] ) as $ref ) {
			$source = $sources[ $ref['source'] ?? '' ] ?? null;
			if ( ! $source instanceof Writable || ! $source->is_available() ) {
				continue;
			}
			$current = $source->find( (string) $ref['id'] );
			if ( ! $current || $current->from !== $ref['from'] || $current->to !== $ref['to'] ) {
				throw new \RuntimeException( __( 'A rule this fix depends on changed since the last scan. Run a new scan first.', 'onehop-redirect-checker' ), 409 );
			}
		}

		// Break a loop: the kept URL's rule goes off, every other rule points straight to that URL.
		$keep_to = 'break' === $fix['action'] ? trim( (string) $fix['rules'][ $keep ]['from'] ) : '';

		// What to write per rule. Retargets first: if one fails they can be put back, which a
		// switched-off (or removed) rule can't, so those come last.
		$ops = [];
		foreach ( $targets as $index => [ $source, $rule ] ) {
			$action = 'break' === $fix['action'] ? ( $index === $keep ? 'disable' : 'retarget' ) : $fix['action'];
			$to     = 'break' === $fix['action'] ? $keep_to : (string) ( $fix['to'] ?? '' );

			// Already points there (the rule that closed the loop onto the kept URL): nothing to write.
			if ( 'retarget' === $action && $rule->to === $to ) {
				continue;
			}
			$ops[] = [ $source, $rule, $action, $to ];
		}
		usort( $ops, static fn( $a, $b ) => ( 'disable' === $a[2] ) <=> ( 'disable' === $b[2] ) );

		$done = [];
		try {
			foreach ( $ops as [ $source, $rule, $action, $to ] ) {
				$before = $rule->to_array();

				if ( 'retarget' === $action ) {
					if ( ! Url_Normalizer::is_safe_target( $to ) || ! $source->retarget( $rule->id, $to ) ) {
						throw new \RuntimeException( __( 'The rule could not be updated. Nothing was changed.', 'onehop-redirect-checker' ), 500 );
					}
					$done[] = [ $source, $rule, 'retargeted', $before, array_merge( $before, [ 'to' => $to ] ) ];
					continue;
				}

				$result = $source->disable( $rule->id );
				if ( '' === $result ) {
					throw new \RuntimeException( __( 'The rule could not be switched off.', 'onehop-redirect-checker' ), 500 );
				}
				$done[] = [ $source, $rule, $result, $before, [] ];
			}
		} catch ( \RuntimeException $error ) {
			self::roll_back( $issue_id, $done );
			throw $error;
		}
		// phpcs:enable

		$changes = [];
		foreach ( $done as [ , $rule, $result, $before, $after ] ) {
			Log_Repository::log_fix( $issue_id, $result, $rule->source, $rule->id, $before, $after );
			$changes[] = [
				'source' => $rule->source,
				'id'     => $rule->id,
				'from'   => $rule->from,
				'result' => $result,
			];
		}

		$user  = wp_get_current_user();
		$fixed = [
			'action'  => (string) $fix['action'],
			'changes' => $changes,
			'by'      => $user->display_name,
			'at'      => gmdate( 'c' ),
		];
		Log_Repository::mark_fixed( $issue_id, $fixed );

		// Pointing a chain's later rules to the end also shortened the chains that start at them:
		// those issues are fixed too (and their own fix would now be refused as out of date).
		if ( 'retarget' === $fix['action'] ) {
			self::close_shortened_chains( (int) $issue['scan_id'], $issue_id, $changes, $fixed );
		}

		Presence::changed( 'fix' );

		return $fixed;
	}

	/**
	 * Put back the retargets of a fix that failed partway, so it can be applied again after the
	 * cause is solved. A rule that was switched off can't be switched back on from here: that
	 * change stays, and is logged.
	 *
	 * @param int     $issue_id Issue ID.
	 * @param array[] $done     Writes so far: [ source, rule, result, before, after ].
	 *
	 * @return void
	 */
	private static function roll_back( int $issue_id, array $done ): void {
		foreach ( array_reverse( $done ) as [ $source, $rule, $result, $before, $after ] ) {
			if ( 'retargeted' === $result && $source->retarget( $rule->id, (string) $before['to'] ) ) {
				continue;
			}
			Log_Repository::log_fix( $issue_id, $result, $rule->source, $rule->id, $before, $after );
		}
	}

	/**
	 * Mark the open chain issues of the scan that start at a rule this fix pointed to the end.
	 *
	 * @param int     $scan_id  Scan ID.
	 * @param int     $issue_id The issue that was fixed.
	 * @param array[] $changes  Changes made: source, id, from, result.
	 * @param array   $fixed    What was done, as stored on the fixed issue.
	 *
	 * @return void
	 */
	private static function close_shortened_chains( int $scan_id, int $issue_id, array $changes, array $fixed ): void {
		$changed = [];
		foreach ( $changes as $change ) {
			$changed[ $change['source'] . ':' . $change['id'] ] = $change;
		}

		foreach ( Log_Repository::open_issues( $scan_id, Analyzer::TYPE_CHAIN ) as $other ) {
			$start = $other['fix']['rules'][0] ?? null;
			$key   = $start ? $start['source'] . ':' . $start['id'] : '';
			if ( (int) $other['id'] !== $issue_id && isset( $changed[ $key ] ) ) {
				Log_Repository::mark_fixed( (int) $other['id'], array_merge( $fixed, [ 'changes' => [ $changed[ $key ] ] ] ) );
			}
		}
	}
}
