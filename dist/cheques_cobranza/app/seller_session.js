(function () {
    'use strict';

    const STORAGE_KEY = 'automarco_seller_context_v2';
    const IS_RENDICIONES = window.location.pathname.includes('/rendiciones/');
    const AUTH_URL = IS_RENDICIONES ? '../api/auth_seller.php' : 'api/auth_seller.php';
    const STATUS_URL = IS_RENDICIONES ? '../api/auth/session_vendedor.php' : 'api/auth/session_vendedor.php';
    const nativeFetch = window.fetch.bind(window);
    let recoveryPromise = null;
    let heartbeatTimer = null;
    let latestCsrfToken = '';

    function normalizeContext(value) {
        const source = value && typeof value === 'object' ? value : {};
        return {
            vendedor_id: String(source.vendedor_id || source.vendedor || '').trim(),
            empresa: String(source.empresa_origen || source.empresa || source.empresa_id || '').trim(),
            vendedor_email: String(source.vendedor_email || source.email || '').trim(),
            vendedor_nombre: String(source.vendedor_nombre || source.nombre || '').trim()
        };
    }

    function contextFromUrl() {
        const params = new URLSearchParams(window.location.search);
        return normalizeContext({
            vendedor_id: params.get('vendedor_id') || params.get('vendedor'),
            empresa: params.get('empresa') || params.get('empresa_id'),
            vendedor_email: params.get('vendedor_email') || params.get('email'),
            vendedor_nombre: params.get('vendedor_nombre') || params.get('nombre')
        });
    }

    function hasIdentity(context) {
        return Boolean(context.empresa && (context.vendedor_id || context.vendedor_email));
    }

    function readStoredContext() {
        try {
            return normalizeContext(JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '{}'));
        } catch (_error) {
            sessionStorage.removeItem(STORAGE_KEY);
            return normalizeContext({});
        }
    }

    function storeContext(seller) {
        const context = normalizeContext(seller);
        if (hasIdentity(context)) {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(context));
        }
        localStorage.removeItem('cached_vendedor_id');
        return context;
    }

    function clearSensitiveUrl() {
        if (window.history && window.history.replaceState && window.location.search) {
            window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
        }
    }

    async function readJson(response) {
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            const error = new Error(payload.message || 'La sesión del vendedor no es válida.');
            error.status = response.status;
            throw error;
        }
        return payload;
    }

    async function authenticate(context) {
        const normalized = normalizeContext(context);
        if (!hasIdentity(normalized)) {
            throw new Error('No se encontró una identidad de vendedor completa para recuperar la sesión.');
        }

        const form = new FormData();
        form.append('empresa', normalized.empresa);
        if (normalized.vendedor_id) form.append('vendedor_id', normalized.vendedor_id);
        if (normalized.vendedor_email) form.append('vendedor_email', normalized.vendedor_email);
        if (normalized.vendedor_nombre) form.append('vendedor_nombre', normalized.vendedor_nombre);

        const payload = await readJson(await nativeFetch(AUTH_URL, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            cache: 'no-store'
        }));
        storeContext(payload.data || {});
        latestCsrfToken = payload.data?.csrf_token || latestCsrfToken;
        return { seller: payload.data || {}, csrfToken: latestCsrfToken };
    }

    async function status() {
        const payload = await readJson(await nativeFetch(STATUS_URL, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        }));
        const seller = payload.data?.seller || {};
        storeContext(seller);
        latestCsrfToken = payload.data?.csrf_token || latestCsrfToken;
        return { seller, csrfToken: latestCsrfToken, expiresIn: payload.data?.expires_in || 0 };
    }

    async function initialize() {
        const urlContext = contextFromUrl();
        if (hasIdentity(urlContext)) {
            const session = await authenticate(urlContext);
            clearSensitiveUrl();
            return session;
        }

        try {
            return await status();
        } catch (_statusError) {
            const storedContext = readStoredContext();
            if (!hasIdentity(storedContext)) throw _statusError;
            return authenticate(storedContext);
        }
    }

    async function recover() {
        if (recoveryPromise) return recoveryPromise;
        recoveryPromise = (async () => {
            try {
                const session = await status().catch(() => authenticate(readStoredContext()));
                document.dispatchEvent(new CustomEvent('seller-session-restored', { detail: session }));
                return true;
            } catch (error) {
                document.dispatchEvent(new CustomEvent('seller-session-expired', { detail: { message: error.message } }));
                return false;
            } finally {
                recoveryPromise = null;
            }
        })();
        return recoveryPromise;
    }

    window.fetch = async function (resource, options) {
        const config = { ...(options || {}), credentials: 'same-origin' };
        const response = await nativeFetch(resource, config);
        const url = typeof resource === 'string' ? resource : (resource?.url || '');
        const isSessionCall = url.includes('auth_seller.php') || url.includes('session_vendedor.php');
        if (response.status !== 401 || isSessionCall || config.__sellerSessionRetry) return response;

        if (!(await recover())) return response;
        const retryHeaders = new Headers(config.headers || {});
        if (latestCsrfToken && retryHeaders.has('X-CSRF-Token')) {
            retryHeaders.set('X-CSRF-Token', latestCsrfToken);
        }
        return nativeFetch(resource, { ...config, headers: retryHeaders, __sellerSessionRetry: true });
    };

    function startHeartbeat() {
        if (heartbeatTimer) return;
        window.setTimeout(() => status().catch(() => recover()), 60000);
        heartbeatTimer = window.setInterval(() => {
            if (document.visibilityState === 'visible') status().catch(() => recover());
        }, 300000);
        window.addEventListener('focus', () => status().catch(() => recover()));
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') status().catch(() => recover());
        });
    }

    window.SellerSession = { initialize, recover, status, startHeartbeat };
    startHeartbeat();
})();
