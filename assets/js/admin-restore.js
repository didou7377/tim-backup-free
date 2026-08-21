(function () {
	'use strict';

	const root = document.querySelector('[data-tim-restore-assistant]');

	if (!root || typeof window.timBackupRestore !== 'object') {
		return;
	}

	const config = window.timBackupRestore;
	const select = root.querySelector('[data-tim-restore-backup]');
	const confirm = root.querySelector('[data-tim-restore-confirm]');
	const start = root.querySelector('[data-tim-restore-start]');
	const cancel = root.querySelector('[data-tim-restore-cancel]');
	const retry = root.querySelector('[data-tim-restore-retry]');
	const rollbackPanel = root.querySelector('[data-tim-rollback-panel]');
	const rollbackDetails = root.querySelector('[data-tim-rollback-details]');
	const rollbackStart = root.querySelector('[data-tim-rollback-start]');
	const rollbackDelete = root.querySelector('[data-tim-rollback-delete]');
	const selection = root.querySelector('[data-tim-restore-selection]');
	const steps = root.querySelector('[data-tim-restore-steps]');
	const detail = root.querySelector('[data-tim-restore-detail]');
	const error = root.querySelector('[data-tim-restore-error]');
	let running = false;

	async function request(action, values = {}) {
		const body = new URLSearchParams({
			action: config.actions[action],
			nonce: config.nonce,
			...values,
		});
		const response = await fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString(),
		});
		const payload = await response.json();

		if (!response.ok || !payload.success) {
			throw new Error(payload?.data?.message || config.text.requestFailed);
		}

		return payload.data;
	}

	function setSelectionLocked(locked) {
		if (select) {
			select.disabled = locked;
		}
		if (confirm) {
			confirm.disabled = locked;
		}
		if (start) {
			start.disabled = locked || !confirm?.checked;
		}
		selection?.classList.toggle('is-locked', locked);
	}

	function render(job) {
		if (!job) {
			return;
		}

		if (rollbackPanel) {
			rollbackPanel.hidden = !job.rollback;
		}
		if (rollbackDetails) {
			rollbackDetails.textContent = job.rollback?.details || '';
		}
		if (rollbackStart) {
			rollbackStart.hidden = !job.rollback?.canRun;
		}
		if (rollbackDelete) {
			rollbackDelete.hidden = !job.rollback?.canDelete;
		}

		setSelectionLocked(job.status === 'active' || Boolean(job.rollback));

		if (!steps) {
			return;
		}

		steps.replaceChildren();

		for (const item of job.steps) {
			const row = document.createElement('li');
			const icon = document.createElement('span');
			const label = document.createElement('span');
			const iconName = item.status === 'done'
				? 'dashicons-yes-alt'
				: item.status === 'active'
					? 'dashicons-update'
					: item.status === 'error'
						? 'dashicons-warning'
						: 'dashicons-marker';

			row.className = `tim-restore-step is-${item.status}`;
			icon.className = `tim-restore-step__icon dashicons ${iconName}`;
			icon.setAttribute('aria-hidden', 'true');
			label.textContent = item.label;
			row.append(icon, label);
			steps.append(row);
		}

		if (error) {
			error.hidden = !job.error;
			error.textContent = job.error || '';
		}

		if (detail) {
			if (job.status === 'completed') {
				detail.textContent = job.message || job.steps[job.steps.length - 1].label;
			} else if (job.phase === 'import' && job.tableTotal > 0) {
				detail.textContent = `${job.tableCurrent} / ${job.tableTotal} · ${job.rowsImported} ${config.text.rows}`;
			} else if (job.status === 'cancelled') {
				detail.textContent = '';
			} else {
				const activeStep = job.steps.find((item) => item.status === 'active' || item.status === 'error');
				detail.textContent = activeStep?.label || '';
			}
		}

		if (cancel) {
			cancel.hidden = !job.canCancel;
		}
		if (retry) {
			retry.hidden = !job.canRetry;
		}

	}

	async function advanceUntilDone(job) {
		if (running) {
			return;
		}

		running = true;

		try {
			let current = job;

			while (current.status === 'active') {
				current = await request('advance');
				render(current);

				if (current.status === 'active') {
					await new Promise((resolve) => window.setTimeout(resolve, 250));
				}
			}
		} catch (exception) {
			if (error) {
				error.hidden = false;
				error.textContent = exception.message || config.text.requestFailed;
			}
			if (detail) {
				detail.textContent = config.text.requestFailed;
			}
		} finally {
			running = false;
		}
	}

	confirm?.addEventListener('change', function () {
		if (start) {
			start.disabled = !confirm.checked;
		}
	});

	start?.addEventListener('click', async function () {
		if (!select?.value || !confirm?.checked || running) {
			return;
		}

		setSelectionLocked(true);

		try {
			const job = await request('start', {backup_id: select.value});
			render(job);
			await advanceUntilDone(job);
		} catch (exception) {
			setSelectionLocked(false);
			if (error) {
				error.hidden = false;
				error.textContent = exception.message || config.text.requestFailed;
			}
		}
	});

	cancel?.addEventListener('click', async function () {
		if (!window.confirm(config.text.cancelConfirm)) {
			return;
		}

		try {
			const job = await request('cancel');
			render(job);
		} catch (exception) {
			if (error) {
				error.hidden = false;
				error.textContent = exception.message || config.text.requestFailed;
			}
		}
	});

	retry?.addEventListener('click', async function () {
		try {
			const job = await request('advance');
			render(job);
			await advanceUntilDone(job);
		} catch (exception) {
			if (error) {
				error.hidden = false;
				error.textContent = exception.message || config.text.requestFailed;
			}
		}
	});

	rollbackStart?.addEventListener('click', async function () {
		if (!window.confirm(config.text.rollbackConfirm) || running) {
			return;
		}

		try {
			const job = await request('rollbackStart');
			render(job);
			await advanceUntilDone(job);
		} catch (exception) {
			if (error) {
				error.hidden = false;
				error.textContent = exception.message || config.text.requestFailed;
			}
		}
	});

	rollbackDelete?.addEventListener('click', async function () {
		if (!window.confirm(config.text.rollbackDeleteConfirm) || running) {
			return;
		}

		try {
			const job = await request('rollbackDelete');
			render(job);
			setSelectionLocked(false);
		} catch (exception) {
			if (error) {
				error.hidden = false;
				error.textContent = exception.message || config.text.requestFailed;
			}
		}
	});

	window.addEventListener('beforeunload', function (event) {
		if (!running) {
			return;
		}

		event.preventDefault();
		event.returnValue = config.text.working;
	});

	request('status')
		.then(function (job) {
			render(job);

			if (job.status === 'active') {
				advanceUntilDone(job);
			}
		})
		.catch(function () {
			setSelectionLocked(false);
		});
}());
