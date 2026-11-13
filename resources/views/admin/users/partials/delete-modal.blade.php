<div class="modal fade" id="enDeleteUser" tabindex="-1" aria-labelledby="enDeleteUserTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" id="enDeleteUserForm">
            @csrf
            @method('DELETE')
            <div class="modal-header">
                <h2 class="modal-title h5" id="enDeleteUserTitle">{{ __('Delete this account?') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-3">
                    <dt class="col-4">{{ __('Name') }}</dt><dd class="col-8" id="enDeleteName"></dd>
                    <dt class="col-4">{{ __('Email') }}</dt><dd class="col-8" id="enDeleteEmail"></dd>
                    <dt class="col-4">{{ __('Role') }}</dt><dd class="col-8 mb-0" id="enDeleteRole"></dd>
                </dl>
                <p class="en-meta">{{ __('The person will not be able to sign in, and an email will be sent to the address above. Applications, documents and audit history are kept, and the account can be restored.') }}</p>
                <p class="en-meta">{{ __('A Citizen or Notary email and mobile number are released, so that person can register again. An Admin email and mobile number are never released.') }}</p>
                <label class="form-label" for="enDeleteReason">{{ __('Reason for deletion') }} <span class="en-required" aria-hidden="true">*</span></label>
                <textarea id="enDeleteReason" name="reason" class="form-control" rows="3" minlength="10" maxlength="500" required aria-describedby="enDeleteReasonHelp"></textarea>
                <div class="form-text" id="enDeleteReasonHelp">{{ __('At least 10 characters. This reason is included in the email to the person.') }}</div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" name="confirm" value="1" id="enDeleteConfirm" required>
                    <label class="form-check-label" for="enDeleteConfirm">{{ __('I confirm that this account should be deleted.') }}</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-danger">{{ __('Confirm Delete') }}</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var element = document.getElementById('enDeleteUser');
        var modal = bootstrap.Modal.getOrCreateInstance(element);
        var form = document.getElementById('enDeleteUserForm');

        document.querySelectorAll('[data-delete-user]').forEach(function (button) {
            button.addEventListener('click', function () {
                form.reset();
                form.action = button.dataset.action;
                document.getElementById('enDeleteName').textContent = button.dataset.name;
                document.getElementById('enDeleteEmail').textContent = button.dataset.email;
                document.getElementById('enDeleteRole').textContent = button.dataset.role;
                modal.show();
            });
        });
    });
</script>
@endpush
