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

    /* ---- Fallback to native WP login flow ----
     * When the AJAX call fails for reasons unrelated to credentials (404 on
     * admin-ajax.php due to WP Hide/similar plugins, network error, timeout,
     * non-JSON response, etc.), release our interception and let the browser
     * post the form to wp-login.php the normal way. The user sees the real
     * outcome instead of a generic "An error occurred" toast.
     */
    var fallbackFired = false;
    function nativeSubmitFallback() {
        if (fallbackFired) return;
        fallbackFired = true;
        form.removeEventListener('submit', onSubmit, false);
        // Keep loading state; the page will navigate immediately.
        try {
            form.submit();
        } catch (err) {
            setLoading(false);
            showToast(brikpanelLogin.i18n.error_generic, 'error');
        }
    }

    /* ---- CAPTCHA detection ----
     * Cloudflare Turnstile, hCaptcha and reCAPTCHA issue short-lived, single-
     * use tokens bound to the wp-login.php submission context. Routing that
     * login through admin-ajax.php confuses the validating plugin, and any
     * retry reuses an already-spent token — Cloudflare then returns
     * `timeout-or-duplicate` and a genuine user is flagged as a bot. When a
     * captcha widget is present on the form, step aside and let WordPress
     * submit natively so the captcha plugin sees its expected environment.
     */
    function hasCaptcha() {
        return !!form.querySelector(
            '.cf-turnstile, .h-captcha, .g-recaptcha, [data-sitekey], ' +
            '[name="cf-turnstile-response"], [name="h-captcha-response"], [name="g-recaptcha-response"]'
        );
    }

    /* ---- Intercept form submit ---- */
    function onSubmit(e) {
        if (hasCaptcha()) {
            return;
        }
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

        // Start from the full form payload so any hidden inputs injected by
        // 3rd-party captcha plugins (cf-turnstile-response, h-captcha-response,
        // g-recaptcha-response, and any plugin-specific nonces) are forwarded
        // verbatim. Then overwrite the fields we control for the AJAX call.
        var data = new FormData(form);
        data.set('action', 'brikpanel_ajax_login');
        data.set('nonce', brikpanelLogin.nonce);
        data.set('username', username.value.trim());
        // Mirror into WP's native login field names so authenticate filters
        // that read $_POST['log']/$_POST['pwd'] (many captcha plugins do)
        // still see the correct credentials.
        data.set('log', username.value.trim());
        data.set('password', password.value);
        data.set('pwd', password.value);
        data.set('remember', remember && remember.checked ? 'true' : 'false');
        if (remember && remember.checked) {
            data.set('rememberme', 'forever');
        }
        if (redirectTo) {
            data.set('redirect_to', redirectTo);
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', brikpanelLogin.ajaxurl, true);
        xhr.timeout = 8000;

        xhr.ontimeout = function () { nativeSubmitFallback(); };
        xhr.onerror   = function () { nativeSubmitFallback(); };

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (fallbackFired) return;

            if (xhr.status === 200) {
                var response;
                try {
                    response = JSON.parse(xhr.responseText);
                } catch (err) {
                    // Non-JSON response (HTML error page, WP Hide interception,
                    // or similar). Hand off to the native login flow.
                    nativeSubmitFallback();
                    return;
                }

                setLoading(false);

                if (response && response.success) {
                    showToast(response.data.message, 'success');
                    submit.value = response.data.message;
                    submit.disabled = true;

                    setTimeout(function () {
                        window.location.href = response.data.redirect;
                    }, 600);
                } else {
                    var msg = response && response.data && response.data.message
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
            } else {
                // Any non-200 (404 from WP Hide, 500 from plugin conflict,
                // 0 from aborted request, etc.) → native form submit.
                nativeSubmitFallback();
            }
        };

        xhr.send(data);
    }

    form.addEventListener('submit', onSubmit, false);
})();
