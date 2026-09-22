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

    const escape = (value) => {
        const node = document.createElement('div');
        node.textContent = value ?? '';

        return node.innerHTML;
    };

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
