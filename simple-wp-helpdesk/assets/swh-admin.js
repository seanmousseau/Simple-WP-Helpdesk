document.addEventListener('DOMContentLoaded', function() {
    var tabs = document.querySelectorAll('.nav-tab');
    var contents = document.querySelectorAll('.swh-tab-content');
    var saveBtn = document.getElementById('save-btn-container');
    var activeTabInput = document.getElementById('swh_active_tab');

    function activateTab(tabId) {
        tabs.forEach(function(t) { t.classList.remove('nav-tab-active'); });
        contents.forEach(function(c) { c.style.display = 'none'; });
        var tabEl = document.getElementById(tabId);
        if (tabEl) { tabEl.style.display = 'block'; }
        tabs.forEach(function(t) { if (t.dataset.tab === tabId) { t.classList.add('nav-tab-active'); } });
        if (saveBtn) { saveBtn.style.display = (tabId === 'tab-tools') ? 'none' : 'block'; }
        if (activeTabInput) { activeTabInput.value = tabId; }
    }

    // Restore active tab from URL param (set after redirect on save).
    var urlParams = new URLSearchParams(window.location.search);
    var savedTab = urlParams.get('swh_tab');
    if (savedTab && document.getElementById(savedTab)) {
        activateTab(savedTab);
    }

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            activateTab(tab.dataset.tab);
        });
    });

    // Reset-to-default: locate field by name attribute stored in data-field-name.
    document.querySelectorAll('.swh-reset-field').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var fieldName = this.previousElementSibling.getAttribute('data-field-name');
            var target = document.querySelector('[name="' + fieldName + '"]');
            if (target) { target.value = target.getAttribute('data-default'); }
        });
    });
});
