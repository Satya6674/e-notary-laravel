{{-- Profile photo: file upload or browser camera. The camera fills the same file field. --}}
@php($account = auth()->user())
<div class="card mb-3">
    <div class="card-header"><h2>{{ __('Profile Photo') }}</h2></div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-start">
            @if($account->photo_path)
                <img src="{{ route('profile.photo.show', $account) }}" alt="{{ __('Profile Photo') }}" width="110" height="110" style="border-radius:12px;object-fit:cover;">
            @else
                <span class="en-avatar en-avatar-fallback" style="width:110px;height:110px;border-radius:12px;font-size:2rem;">{{ mb_substr($account->name, 0, 1) }}</span>
            @endif

            <div class="flex-grow-1" style="min-width:240px;">
                <form method="POST" action="{{ route('profile.photo.store') }}" enctype="multipart/form-data" id="photoForm">
                    @csrf
                    <label for="photoFile" class="form-label">{{ __('Choose a JPG or PNG (2 MB maximum)') }}</label>
                    <input id="photoFile" type="file" name="photo" accept="image/jpeg,image/png" class="form-control mb-2">
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">{{ __('Save Photo') }}</button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="cameraStart"><i class="fas fa-camera me-1"></i>{{ __('Use Camera') }}</button>
                    </div>
                </form>

                <div class="en-camera mt-3" id="cameraPanel" hidden>
                    <video id="cameraVideo" autoplay playsinline muted></video>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-gold" id="cameraCapture">{{ __('Capture') }}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="cameraStop">{{ __('Cancel') }}</button>
                    </div>
                </div>
                <p class="text-danger small mt-2 mb-0" id="cameraError" hidden>{{ __('The camera is not available. Please choose a file instead.') }}</p>

                @if($account->photo_path)
                    <form method="POST" action="{{ route('profile.photo.destroy') }}" class="mt-2">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-link text-danger p-0">{{ __('Remove Photo') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var panel = document.getElementById('cameraPanel');
    var video = document.getElementById('cameraVideo');
    var error = document.getElementById('cameraError');
    var stream = null;

    function stop() {
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        panel.hidden = true;
    }

    document.getElementById('cameraStart').addEventListener('click', function () {
        error.hidden = true;

        if (! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia) { error.hidden = false; return; }

        navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 } }).then(function (s) {
            stream = s;
            video.srcObject = s;
            panel.hidden = false;
        }).catch(function () { error.hidden = false; });
    });

    document.getElementById('cameraStop').addEventListener('click', stop);

    document.getElementById('cameraCapture').addEventListener('click', function () {
        var canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);

        canvas.toBlob(function (blob) {
            var files = new DataTransfer();
            files.items.add(new File([blob], 'camera.jpg', { type: 'image/jpeg' }));
            document.getElementById('photoFile').files = files.files;
            stop();
            document.getElementById('photoForm').submit();
        }, 'image/jpeg', 0.9);
    });
})();
</script>
@endpush
