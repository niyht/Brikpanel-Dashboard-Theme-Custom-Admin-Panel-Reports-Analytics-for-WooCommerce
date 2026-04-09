/**
 * BrikPanel — AJAX Login
 */
(function () {
    'use strict';

    var form   = document.getElementById('loginform');
    var submit = document.getElementById('wp-submit');

    if (!form || !submit) {
        return;
    }

    var toast = document.getElementById('brikpanel-toast');

    /* ---- Toast helper ---- */
    function showToast(message, type) {
        if (!toast) return;
        toast.textContent = message;
        toast.className = 'brikpanel-toast is-visible is-' + type;
        setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 3500);
    }

    /* ---- Set loading state ---- */
    function setLoading(loading) {
        if (loading) {
            submit.disabled = true;
            submit.dataset.originalText = submit.value;
            submit.value = brikpanelLogin.i18n.logging_in;
            submit.insertAdjacentHTML('afterend', '<span class="brikpanel-spinner" id="brikpanel-login-spinner"></span>');
            // Move spinner inside the button visual area via CSS
            var spinner = document.getElementById('brikpanel-login-spinner');
            if (spinner) {
                submit.parentNode.style.position = 'relative';
                spinner.style.position = 'absolute';
                spinner.style.right = '1rem';
                spinner.style.top = '50%';
                spinner.style.transform = 'translateY(-50%)';
            }
        } else {
            submit.disabled = false;
            submit.value = submit.dataset.originalText || brikpanelLogin.i18n.login;
            var existingSpinner = document.getElementById('brikpanel-login-spinner');
            if (existingSpinner) existingSpinner.remove();
        }
    }

    /* ---- Clear field errors ---- */
    function clearErrors() {
        var inputs = form.querySelectorAll('.brikpanel-input-error');
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].classList.remove('brikpanel-input-error');
        }
    }

    /* ---- Highlight error field ---- */
    function highlightField(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.add('brikpanel-input-error');
        el.focus();
        form.classList.add('brikpanel-shake');
        setTimeout(function () {
            form.classList.remove('brikpanel-shake');
        }, 400);
    }

    /* ---- Remove default WP error messages ---- */
    function clearWPErrors() {
        var errors = document.querySelectorAll('#login_error');
        for (var i = 0; i < errors.length; i++) {
            errors[i].remove();
        }
    }

    /* ---- Intercept form submit ---- */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearErrors();
        clearWPErrors();

        var username = document.getElementById('user_login');
        var password = document.getElementById('user_pass');
        var remember = document.getElementById('rememberme');

        if (!username || !username.value.trim()) {
            highlightField('user_login');
            showToast(brikpanelLogin.i18n.error_generic, 'error');
            return;
        }

        if (!password || !password.value) {
            highlightField('user_pass');
            showToast(brikpanelLogin.i18n.error_generic, 'error');
            return;
        }

        setLoading(true);

        // Get redirect_to from URL if present
        var urlParams = new URLSearchParams(window.location.search);
        var redirectTo = urlParams.get('redirect_to') || '';

        var data = new FormData();
        data.append('action', 'brikpanel_ajax_login');
        data.append('nonce', brikpanelLogin.nonce);
        data.append('username', username.value.trim());
        data.append('password', password.value);
        data.append('remember', remember && remember.checked ? 'true' : 'false');
        if (redirectTo) {
            data.append('redirect_to', redirectTo);
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', brikpanelLogin.ajaxurl, true);

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            setLoading(false);

            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);

                    if (response.success) {
                        showToast(response.data.message, 'success');
                        submit.value = response.data.message;
                        submit.disabled = true;

                        setTimeout(function () {
                            window.location.href = response.data.redirect;
                        }, 600);
                    } else {
                        var msg = response.data && response.data.message
                            ? response.data.message
                            : brikpanelLogin.i18n.error_generic;

                        showToast(msg, 'error');

                        // Highlight relevant field based on error
                        if (msg.toLowerCase().indexOf('username') !== -1 || msg.toLowerCase().indexOf('email') !== -1) {
                            highlightField('user_login');
                        } else if (msg.toLowerCase().indexOf('password') !== -1) {
                            highlightField('user_pass');
                        } else {
                            highlightField('user_login');
                        }
                    }
                } catch (err) {
                    showToast(brikpanelLogin.i18n.error_generic, 'error');
                }
            } else {
                showToast(brikpanelLogin.i18n.error_generic, 'error');
            }
        };

        xhr.send(data);
    });
})();
