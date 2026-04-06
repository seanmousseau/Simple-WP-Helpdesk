document.addEventListener('DOMContentLoaded', function() {
    if (typeof swhConfig === 'undefined') {
        return;
    }
    var maxBytes = swhConfig.maxMb * 1024 * 1024;
    document.querySelectorAll('.swh-file-input').forEach(function(input) {
        input.addEventListener('change', function() {
            if (swhConfig.maxFiles > 0 && this.files.length > swhConfig.maxFiles) {
                alert('You may only attach up to ' + swhConfig.maxFiles + ' file(s) per upload.');
                this.value = '';
                return;
            }
            var errorMsg = '';
            for (var i = 0; i < this.files.length; i++) {
                var file = this.files[i];
                var ext = file.name.split('.').pop().toLowerCase();
                if (swhConfig.allowedExts.indexOf(ext) === -1) {
                    errorMsg += 'File "' + file.name + '" has an invalid type.\n';
                }
                if (file.size > maxBytes) {
                    errorMsg += 'File "' + file.name + '" exceeds the ' + swhConfig.maxMb + 'MB size limit.\n';
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
