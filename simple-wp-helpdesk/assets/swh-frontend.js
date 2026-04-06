document.addEventListener('DOMContentLoaded', function() {
    if (typeof swhConfig === 'undefined') {
        return;
    }
    var maxBytes = swhConfig.maxMb * 1024 * 1024;
    document.querySelectorAll('.swh-file-input').forEach(function(input) {
        input.addEventListener('change', function() {
            if (swhConfig.maxFiles > 0 && this.files.length > swhConfig.maxFiles) {
                alert(swhConfig.i18n.maxFilesError.replace('%d', swhConfig.maxFiles));
                this.value = '';
                return;
            }
            var errorMsg = '';
            for (var i = 0; i < this.files.length; i++) {
                var file = this.files[i];
                var ext = file.name.split('.').pop().toLowerCase();
                if (swhConfig.allowedExts.indexOf(ext) === -1) {
                    errorMsg += swhConfig.i18n.invalidType.replace('%s', file.name) + '\n';
                }
                if (file.size > maxBytes) {
                    errorMsg += swhConfig.i18n.sizeExceeded.replace('%s', file.name).replace('%d', swhConfig.maxMb) + '\n';
                }
            }
            if (errorMsg !== '') {
                alert(errorMsg);
                this.value = '';
            }
        });
    });
    var toggleLink = document.getElementById('swh-toggle-lookup');
    if (toggleLink) {
        toggleLink.addEventListener('click', function(e) {
            e.preventDefault();
            var form = document.getElementById('swh-lookup-form');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        });
    }
});
