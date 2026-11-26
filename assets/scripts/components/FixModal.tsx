import { Button, Modal, RadioControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import type { Fix, FixRule } from '../api/types';

/** Plugins without an "off" state: switching a rule off removes it there. */
export const NO_DISABLE = ['yoast', 'simple-301'];

interface Change {
	rule: FixRule;
	/** New target, or null when the rule is switched off. */
	to: string | null;
	/** Already points there: nothing is written. */
	unchanged: boolean;
}

interface Props {
	fix: Fix;
	busy: boolean;
	error: string | null;
	onApply: (keep: number) => void;
	onClose: () => void;
}

/** What each rule becomes: a chain points every rule to the end; a loop to the URL that is kept. */
const changes = (fix: Fix, keep: number): Change[] => {
	if ('break' === fix.action) {
		const to = fix.rules[keep].from.trim();
		return fix.rules.map((rule, index) => (index === keep ? { rule, to: null, unchanged: false } : { rule, to, unchanged: rule.to === to }));
	}
	const to = fix.to ?? '';
	return [
		...fix.rules.map((rule) => ({ rule, to, unchanged: rule.to === to })),
		...(fix.requires ?? []).map((rule) => ({ rule, to: rule.to, unchanged: true })),
	];
};

/**
 * Fix a chain or a loop after seeing every rule it changes. A chain has an end: every rule points
 * straight to it. A loop has none, so the person picks the URL that should load; its rule goes
 * off and every other URL in the loop redirects straight to it.
 */
export const FixModal = ({ fix, busy, error, onApply, onClose }: Props) => {
	const candidates = fix.candidates ?? [];
	const [keep, setKeep] = useState(candidates[0] ?? 0);
	const isLoop = 'break' === fix.action;
	const rows = changes(fix, keep);
	const writes = rows.filter((row) => !row.unchanged).length;

	return (
		<Modal
			title={isLoop ? __('Break the redirect loop', 'onehop-redirect-checker') : __('Shorten the redirect chain', 'onehop-redirect-checker')}
			onRequestClose={() => !busy && onClose()}
			size="medium"
			className="rdi-migrate rdi-fix"
		>
			<p className="rdi-migrate__intro">
				{isLoop
					? __('A loop has no end, so choose the URL that should load as a page. Its redirect is switched off, and every other URL in the loop redirects straight to it.', 'onehop-redirect-checker')
					: sprintf(
						/* translators: %s: final URL. */
						__('Point every URL in this chain straight to %s, so each one takes a single hop.', 'onehop-redirect-checker'),
						fix.to ?? ''
					)}
			</p>

			{isLoop && (
				<RadioControl
					className="rdi-fix__keep"
					label={__('URL that should load', 'onehop-redirect-checker')}
					selected={String(keep)}
					options={candidates.map((index) => ({ label: fix.rules[index].from, value: String(index) }))}
					onChange={(value: string) => setKeep(Number(value))}
				/>
			)}

			<div className="rdi-table-wrap" role="region" aria-label={__('Changes', 'onehop-redirect-checker')} tabIndex={0}>
				<table className="rdi-table rdi-migrate__table">
					<thead>
						<tr>
							<th scope="col">{_x('From', 'table column: source URL of a redirect rule', 'onehop-redirect-checker')}</th>
							<th scope="col">{_x('To', 'table column: target URL of a redirect rule', 'onehop-redirect-checker')}</th>
							<th scope="col">{__('Plugin', 'onehop-redirect-checker')}</th>
						</tr>
					</thead>
					<tbody>
						{rows.map(({ rule, to, unchanged }) => (
							<tr key={`${rule.source}:${rule.id}`}>
								<td><code>{rule.from}</code></td>
								<td>
									{null === to ? (
										<span className="rdi-migrate__status is-unsupported">
											{NO_DISABLE.includes(rule.source)
												? __('Removed (kept in the change log)', 'onehop-redirect-checker')
												: __('Switched off', 'onehop-redirect-checker')}
										</span>
									) : unchanged ? (
										<>
											<code>{to}</code> <span className="rdi-migrate__status is-none">{__('(stays)', 'onehop-redirect-checker')}</span>
										</>
									) : (
										<>
											<del><code>{rule.to}</code></del> <code>{to}</code>
										</>
									)}
								</td>
								<td>{rule.label}</td>
							</tr>
						))}
					</tbody>
				</table>
			</div>

			{isLoop && (
				<p className="rdi-fix__note">{__('Afterwards, use “Verify live” to check that the chosen URL shows a page.', 'onehop-redirect-checker')}</p>
			)}

			{error && <p className="rdi-migrate__error" role="alert">{error}</p>}

			<div className="rdi-migrate__footer">
				<div className="rdi-migrate__buttons">
					<Button __next40pxDefaultSize variant="tertiary" disabled={busy} accessibleWhenDisabled onClick={onClose}>
						{__('Cancel', 'onehop-redirect-checker')}
					</Button>
					<Button __next40pxDefaultSize variant="primary" isBusy={busy} disabled={busy || !writes} accessibleWhenDisabled onClick={() => onApply(keep)}>
						{busy
							? __('Applying…', 'onehop-redirect-checker')
							: sprintf(/* translators: %d: number of rules. */ _n('Change %d rule', 'Change %d rules', writes, 'onehop-redirect-checker'), writes)}
					</Button>
				</div>
			</div>
		</Modal>
	);
};
