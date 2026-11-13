{{-- Replaces browser confirm(). A form opts in with data-confirm="message"; data-reason also asks for a reason. --}}
<div class="modal fade" id="enConfirm" tabindex="-1" aria-labelledby="enConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="enConfirmTitle">{{ __('Please confirm') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button></div>
            <div class="modal-body">
                <p id="enConfirmText" class="mb-0"></p>
                <div id="enConfirmReasonWrap" class="mt-3" hidden>
                    <label class="form-label" for="enConfirmReason">{{ __('Reason') }} <span class="en-required" aria-hidden="true">*</span></label>
                    <textarea id="enConfirmReason" class="form-control" rows="3" maxlength="500"></textarea>
                    <div class="invalid-feedback" id="enConfirmHint" data-template="{{ __('Please give a reason of at least :count characters.', ['count' => '__COUNT__']) }}">{{ __('Please give a reason of at least 3 characters.') }}</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="enConfirmGo">{{ __('Confirm') }}</button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('enConfirm'));
    var reason = document.getElementById('enConfirmReason');
    var wrap = document.getElementById('enConfirmReasonWrap');
    var pending = null;

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (! form.matches('form[data-confirm]') || form.dataset.confirmed) { return; }

        event.preventDefault();
        pending = form;
        document.getElementById('enConfirmText').textContent = form.dataset.confirm;
        wrap.hidden = ! form.hasAttribute('data-reason');
        var hint = document.getElementById('enConfirmHint');
        hint.textContent = hint.dataset.template.replace('__COUNT__', String(parseInt(form.dataset.reasonMin, 10) || 3));
        reason.value = '';
        reason.classList.remove('is-invalid');
        modal.show();
    });

    document.getElementById('enConfirmGo').addEventListener('click', function () {
        if (! pending) { return; }

        if (pending.hasAttribute('data-reason')) {
            // A form may ask for a longer reason, matching what its server side requires.
            if (reason.value.trim().length < (parseInt(pending.dataset.reasonMin, 10) || 3)) {
                reason.classList.add('is-invalid');
                reason.focus();
                return;
            }

            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'reason';
            input.value = reason.value.trim();
            pending.appendChild(input);
        }

        pending.dataset.confirmed = '1';
        pending.submit();
    });
});
</script>
