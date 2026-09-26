/**
 * Install the app on the phone of whoever is holding it.
 *
 * Registered late so it never competes with the first paint, and silently:
 * a technician who never installs the app is still a technician the follow-up
 * engine reaches by SMS, which is the whole point of the design.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // An unregistered worker costs the offline page and nothing else.
        });
    });
}

/**
 * The install prompt, offered once rather than nagged.
 *
 * Chrome fires this event when it judges the app installable; iOS never does,
 * so Safari users get the hint in the banner markup instead.
 */
const installBanner = document.getElementById('install-banner');

if (installBanner) {
    let deferredPrompt = null;

    const dismissedKey = 'install-prompt-dismissed';

    const alreadyDismissed = () => {
        try {
            return localStorage.getItem(dismissedKey) === '1';
        } catch {
            // Private browsing throws on access. Not a reason to show the
            // banner forever, but not a reason to crash either.
            return false;
        }
    };

    const remember = () => {
        try {
            localStorage.setItem(dismissedKey, '1');
        } catch {
            /* nothing to do */
        }
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event;

        if (!alreadyDismissed()) {
            installBanner.classList.remove('hidden');
        }
    });

    installBanner.querySelector('[data-install]')?.addEventListener('click', async () => {
        if (!deferredPrompt) {
            return;
        }

        deferredPrompt.prompt();
        await deferredPrompt.userChoice;
        deferredPrompt = null;
        installBanner.classList.add('hidden');
        remember();
    });

    installBanner.querySelector('[data-dismiss]')?.addEventListener('click', () => {
        installBanner.classList.add('hidden');
        remember();
    });
}

/**
 * Explain the recurrence anchor as it is chosen.
 *
 * The choice is invisible until months later — a bill paid late that drags
 * every future bill with it, or a service booked far too soon — so the
 * consequence is spelled out at the moment somebody picks one.
 */
const anchorSelect = document.querySelector('[data-anchor]');

if (anchorSelect) {
    const hint = document.querySelector('[data-anchor-hint]');

    const showHint = () => {
        const option = anchorSelect.selectedOptions[0];

        if (hint && option) {
            hint.textContent = option.dataset.hint ?? '';
        }
    };

    anchorSelect.addEventListener('change', showHint);
    showHint();
}

/**
 * Move the notice window to the default for the kind of contract chosen.
 *
 * Sixty days for a staff contract and thirty for a lease are not a
 * preference, they are what each one needs — but only until somebody types
 * their own number, which is never overwritten afterwards.
 */
const contractKind = document.querySelector('[data-contract-kind]');

if (contractKind) {
    const noticeDays = document.querySelector('[data-notice-days]');
    let touched = false;

    noticeDays?.addEventListener('input', () => {
        touched = true;
    });

    contractKind.addEventListener('change', () => {
        const option = contractKind.selectedOptions[0];

        if (noticeDays && option && !touched) {
            noticeDays.value = option.dataset.notice ?? noticeDays.value;
        }
    });
}

/**
 * A confirmation step on the destructive actions.
 *
 * Cancelling a task deletes its remaining follow-ups, which is not something
 * to do on a misclick — and unlike completing a task, there is no button that
 * undoes it.
 */
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

/**
 * The referral link: copy it, or hand it to the phone's own share sheet where
 * there is one. Most owners will paste it into a chat, so copying has to work
 * on the first tap and say that it did.
 */
const referral = document.querySelector('[data-referral]');

if (referral) {
    const field = referral.querySelector('[data-referral-link]');
    const copyButton = referral.querySelector('[data-referral-copy]');
    const shareButton = referral.querySelector('[data-referral-share]');

    field.addEventListener('focus', () => field.select());

    copyButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(field.value);
        } catch {
            // Older browsers and plain http: fall back to selecting it.
            field.select();
            document.execCommand('copy');
        }

        const label = copyButton.textContent;
        copyButton.textContent = 'کپی شد';
        setTimeout(() => (copyButton.textContent = label), 2000);
    });

    if (navigator.share) {
        shareButton.classList.remove('hidden');
        shareButton.addEventListener('click', () => {
            navigator.share({ text: shareButton.dataset.shareText, url: field.value }).catch(() => {});
        });
    }
}

/**
 * Show only the fields the chosen request type actually uses.
 *
 * Progressive enhancement: with JavaScript off every field is visible and the
 * form still submits correctly, because the server is what decides which ones
 * it stores.
 */
const approvalType = document.querySelector('[data-approval-type]');

if (approvalType) {
    const dates = document.querySelector('[data-approval-dates]');
    const amount = document.querySelector('[data-approval-amount]');

    const sync = () => {
        dates?.classList.toggle('hidden', approvalType.value !== 'leave');
        amount?.classList.toggle(
            'hidden',
            !['purchase', 'expense'].includes(approvalType.value),
        );
    };

    approvalType.addEventListener('change', sync);
    sync();
}

/**
 * The free-text shortcut on the tasks page.
 *
 * Deliberately plain: it posts a paragraph, renders whatever drafts come back
 * as a pre-filled form the manager confirms, and says so plainly when the
 * model is unavailable. Nothing here creates a task — the manual form below it
 * always works, so the AI is a shortcut and never a gate.
 */

const parseButton = document.getElementById('ai-parse');

if (parseButton) {
    const textarea = document.getElementById('ai-text');
    const message = document.getElementById('ai-message');
    const drafts = document.getElementById('ai-drafts');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    const show = (text, tone) => {
        message.textContent = text;
        message.className = `mt-2 text-xs ${
            tone === 'error' ? 'text-red-600' : 'text-slate-500'
        }`;
        message.classList.remove('hidden');
    };

    // Escapes for text AND attribute values. Serialising a text node only
    // escapes <, > and &, which left a quote in a model-written title free to
    // close value="…" and add an event handler to the draft form.
    const escape = (value) =>
        String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');

    parseButton.addEventListener('click', async () => {
        const text = textarea.value.trim();

        if (text.length < 5) {
            show('متن را کامل‌تر بنویسید.', 'error');

            return;
        }

        parseButton.disabled = true;
        parseButton.textContent = 'در حال خواندن…';
        drafts.classList.add('hidden');
        drafts.innerHTML = '';

        try {
            const response = await fetch('/tasks/parse', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({ text }),
            });

            const body = await response.json();

            if (!response.ok || !body.ok) {
                show(body.message ?? 'خطایی رخ داد. تسک را دستی ثبت کنید.', 'error');

                return;
            }

            if (!body.tasks?.length) {
                show('تسکی در این متن پیدا نشد. دستی ثبت کنید.', 'error');

                return;
            }

            // Every draft is a separate form posting to the ordinary store
            // endpoint, so a confirmed draft goes through exactly the same
            // validation and scheduling as anything typed by hand.
            drafts.innerHTML = body.tasks
                .map(
                    (task) => `
                    <form method="POST" action="/tasks" class="rounded-xl border border-slate-200 p-3">
                        <input type="hidden" name="_token" value="${escape(token)}">
                        <input type="hidden" name="title" value="${escape(task.title)}">
                        <input type="hidden" name="assignee_id" value="${escape(task.assignee_id ?? '')}">
                        <input type="hidden" name="due_date" value="${escape(task.due_date ?? '')}">
                        <input type="hidden" name="priority" value="${escape(task.priority)}">
                        <p class="text-sm font-medium">${escape(task.title)}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            ${escape(task.assignee_name ?? 'بدون مسئول')}
                            ${task.due_date ? ` · <span class="tabular">${escape(task.due_date)}</span>` : ' · بدون ددلاین'}
                        </p>
                        <button type="submit" class="mt-2 w-full rounded-lg bg-emerald-600 px-3 py-1.5 text-xs text-white hover:bg-emerald-700">
                            تأیید و ثبت
                        </button>
                    </form>
                `,
                )
                .join('');

            drafts.classList.remove('hidden');
            show(`${body.tasks.length} تسک پیشنهاد شد. هرکدام را تأیید کنید.`);
        } catch {
            show('ارتباط برقرار نشد. تسک را دستی ثبت کنید.', 'error');
        } finally {
            parseButton.disabled = false;
            parseButton.textContent = 'استخراج تسک‌ها';
        }
    });
}
