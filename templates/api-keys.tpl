{literal}
<style>
.fps-keys{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#d0d8f0;line-height:1.7;max-width:1100px;margin:0 auto;}
.fps-keys *{box-sizing:border-box;}
.fps-keys-hero{background:linear-gradient(135deg,#0f0c29 0%,#1a1a3e 50%,#302b63 100%);color:#fff;padding:60px 30px;text-align:center;border-radius:16px;margin-bottom:40px;position:relative;overflow:hidden;}
.fps-keys-hero::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(ellipse at 30% 40%,rgba(102,126,234,0.12),transparent 60%),radial-gradient(ellipse at 70% 60%,rgba(118,75,162,0.08),transparent 50%);pointer-events:none;}
.fps-keys-hero h1{font-size:2.2rem;font-weight:800;margin:0 0 12px;letter-spacing:-0.5px;position:relative;z-index:1;}
.fps-keys-hero h1 i{color:#667eea;margin-right:10px;}
.fps-keys-hero p{font-size:1.1rem;color:#b0b8d1;margin:0 auto;max-width:700px;position:relative;z-index:1;}
.fps-keys-hero .fps-keys-version{background:rgba(102,126,234,0.2);border:1px solid rgba(102,126,234,0.3);border-radius:20px;padding:4px 16px;font-size:0.85rem;color:#a0b0ff;display:inline-block;margin-bottom:16px;position:relative;z-index:1;}
.fps-keys-section{margin-bottom:40px;}
.fps-keys-section h2{font-size:1.5rem;font-weight:700;color:#ffffff;margin:0 0 8px;display:flex;align-items:center;gap:10px;padding-bottom:12px;border-bottom:1px solid rgba(255,255,255,0.1);}
.fps-keys-section h2 i{color:#667eea;}
.fps-keys-section p.subtitle{font-size:0.95rem;color:#8892b0;margin:8px 0 20px;}
.fps-keys-card{background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:28px;margin-bottom:24px;}
.fps-keys-login-msg{text-align:center;padding:48px 24px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;}
.fps-keys-login-msg i{font-size:3rem;color:#667eea;display:block;margin-bottom:16px;}
.fps-keys-login-msg p{color:#8892b0;margin:0 0 20px;font-size:1.05rem;}
.fps-keys-login-msg a{display:inline-block;padding:12px 28px;background:#667eea;color:#fff;border-radius:8px;font-weight:700;text-decoration:none;transition:all 0.2s;}
.fps-keys-login-msg a:hover{background:#5a6fd6;}
.fps-keys-form{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;}
.fps-keys-form-group{display:flex;flex-direction:column;gap:4px;}
.fps-keys-form-group label{font-size:0.85rem;font-weight:600;color:#a0b0ff;text-transform:uppercase;letter-spacing:0.5px;}
.fps-keys-form-group input,.fps-keys-form-group select{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.15);border-radius:8px;padding:10px 14px;color:#e0e8ff;font-size:0.95rem;min-width:200px;}
.fps-keys-form-group input:focus,.fps-keys-form-group select:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,0.2);}
.fps-keys-form-group select option{background:#1a1a3e;color:#e0e8ff;}
.fps-keys-btn{padding:10px 24px;border:none;border-radius:8px;font-weight:700;font-size:0.92rem;cursor:pointer;transition:all 0.2s;display:inline-flex;align-items:center;gap:6px;}
.fps-keys-btn-primary{background:#667eea;color:#fff;}
.fps-keys-btn-primary:hover{background:#5a6fd6;}
.fps-keys-btn-danger{background:rgba(235,51,73,0.15);color:#ff8090;border:1px solid rgba(235,51,73,0.3);}
.fps-keys-btn-danger:hover{background:rgba(235,51,73,0.25);}
.fps-keys-btn-secondary{background:rgba(255,255,255,0.08);color:#a0b0ff;border:1px solid rgba(255,255,255,0.15);}
.fps-keys-btn-secondary:hover{background:rgba(255,255,255,0.15);}
.fps-keys-btn:disabled{opacity:0.5;cursor:not-allowed;}
.fps-keys-table{width:100%;border-collapse:collapse;margin:16px 0;}
.fps-keys-table th{background:rgba(102,126,234,0.15);color:#a0b0ff;font-weight:700;text-align:left;padding:10px 14px;font-size:0.82rem;text-transform:uppercase;letter-spacing:0.5px;border-bottom:2px solid rgba(102,126,234,0.2);}
.fps-keys-table td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,0.06);font-size:0.9rem;color:#c0c8e0;vertical-align:middle;}
.fps-keys-table tr:hover td{background:rgba(102,126,234,0.06);}
.fps-keys-masked{font-family:'Fira Code',monospace;font-size:0.85rem;color:#8892b0;}
.fps-keys-copy-btn{background:none;border:1px solid rgba(255,255,255,0.15);border-radius:4px;padding:2px 8px;color:#a0b0ff;cursor:pointer;font-size:0.78rem;transition:all 0.2s;}
.fps-keys-copy-btn:hover{background:rgba(102,126,234,0.15);border-color:#667eea;}
.fps-keys-badge{padding:3px 10px;border-radius:12px;font-size:0.78rem;font-weight:700;text-transform:uppercase;}
.fps-keys-badge-active{background:rgba(56,239,125,0.15);color:#38ef7d;border:1px solid rgba(56,239,125,0.3);}
.fps-keys-badge-revoked{background:rgba(235,51,73,0.15);color:#ff8090;border:1px solid rgba(235,51,73,0.3);}
.fps-keys-badge-free{background:rgba(56,239,125,0.15);color:#38ef7d;border:1px solid rgba(56,239,125,0.3);}
.fps-keys-badge-basic{background:rgba(102,126,234,0.15);color:#a0b0ff;border:1px solid rgba(102,126,234,0.3);}
.fps-keys-badge-premium{background:rgba(235,51,73,0.15);color:#ff8090;border:1px solid rgba(235,51,73,0.3);}
.fps-keys-alert{padding:16px 20px;border-radius:10px;margin:16px 0;display:none;font-size:0.95rem;}
.fps-keys-alert-success{background:rgba(56,239,125,0.1);border:1px solid rgba(56,239,125,0.3);color:#38ef7d;display:block;}
.fps-keys-alert-error{background:rgba(235,51,73,0.1);border:1px solid rgba(235,51,73,0.3);color:#ff8090;display:block;}
.fps-keys-new-key-display{background:#0d0d1a;border:2px solid #38ef7d;border-radius:10px;padding:20px;margin:16px 0;text-align:center;display:none;}
.fps-keys-new-key-display.visible{display:block;}
.fps-keys-new-key-display .key-value{font-family:'Fira Code',monospace;font-size:1.1rem;color:#38ef7d;word-break:break-all;margin:8px 0;padding:12px;background:rgba(56,239,125,0.08);border-radius:6px;}
.fps-keys-new-key-display .key-warning{color:#ff8090;font-weight:700;font-size:0.9rem;margin-top:12px;}
.fps-keys-empty{text-align:center;padding:40px 24px;color:#8892b0;}
.fps-keys-empty i{font-size:2.5rem;color:#555;display:block;margin-bottom:12px;}
@media(max-width:768px){.fps-keys-hero h1{font-size:1.5rem;}.fps-keys-form{flex-direction:column;}.fps-keys-table{display:block;overflow-x:auto;}}
</style>
{/literal}

<div class="fps-keys">

    {* === HERO === *}
    <div class="fps-keys-hero">
        <div class="fps-keys-version">v{$module_version}</div>
        <h1><i class="fas fa-key"></i> API Key Management</h1>
        <p>Generate and manage API keys to access the Fraud Prevention Suite REST API.</p>
    </div>

    {if !$logged_in}
        {* === NOT LOGGED IN === *}
        <div class="fps-keys-login-msg">
            <i class="fas fa-user-lock"></i>
            <p>Please log in to your account to manage API keys.</p>
            <a href="login.php?goto={literal}index.php%3Fm%3Dfraud_prevention_suite%26page%3Dapi-keys{/literal}">
                <i class="fas fa-sign-in-alt"></i> Log In
            </a>
        </div>
    {else}
        {* === CREATE NEW KEY === *}
        <div class="fps-keys-section">
            <h2><i class="fas fa-plus-circle"></i> Create New Key</h2>
            <p class="subtitle">Free tier keys include 10 requests/minute and 1,000 requests/day. Maximum 3 active keys per account.</p>
        </div>

        <div class="fps-keys-card">
            <div id="fps-key-alert"></div>
            <div id="fps-new-key-display" class="fps-keys-new-key-display">
                <strong style="color:#fff;font-size:1rem;">Your new API key has been created!</strong>
                <div class="key-value" id="fps-new-key-value"></div>
                <button class="fps-keys-btn fps-keys-btn-primary" onclick="FpsClientApi.copyNewKey()" style="margin-top:8px;">
                    <i class="fas fa-copy"></i> Copy Key
                </button>
                <div class="key-warning"><i class="fas fa-exclamation-triangle"></i> Copy this key now. It will not be shown again.</div>
            </div>
            <div class="fps-keys-form" id="fps-create-form">
                <div class="fps-keys-form-group">
                    <label>Key Name</label>
                    <input type="text" id="fps-key-name" placeholder="e.g. My Integration" maxlength="100">
                </div>
                <div class="fps-keys-form-group">
                    <label>Tier</label>
                    <select id="fps-key-tier" disabled>
                        <option value="free">Free</option>
                    </select>
                </div>
                <button class="fps-keys-btn fps-keys-btn-primary" id="fps-create-btn" onclick="FpsClientApi.createKey()">
                    <i class="fas fa-key"></i> Generate Key
                </button>
            </div>
            <p style="color:#8892b0;font-size:0.85rem;margin:12px 0 0;">Need Basic or Premium tier? <a href="submitticket.php" style="color:#667eea;">Contact sales</a> to upgrade.</p>
        </div>

        {* === YOUR KEYS === *}
        <div class="fps-keys-section">
            <h2><i class="fas fa-list"></i> Your API Keys</h2>
            <p class="subtitle">Manage your existing API keys. Revoked keys stop working immediately but usage history is preserved.</p>
        </div>

        <div class="fps-keys-card" id="fps-keys-list-card">
            {if $keys|@count > 0}
                <table class="fps-keys-table" id="fps-keys-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Key</th>
                            <th>Tier</th>
                            <th>Created</th>
                            <th>Last Used</th>
                            <th>Requests</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$keys item=key}
                            <tr id="fps-key-row-{$key->id}">
                                <td><strong>{$key->name|escape}</strong></td>
                                <td>
                                    <span class="fps-keys-masked">{$key->key_prefix}...****</span>
                                    <button class="fps-keys-copy-btn" onclick="FpsClientApi.copyPrefix('{$key->key_prefix}')" title="Copy prefix">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </td>
                                <td><span class="fps-keys-badge fps-keys-badge-{$key->tier}">{$key->tier|upper}</span></td>
                                <td>{$key->created_at|date_format:"%Y-%m-%d"}</td>
                                <td>{if $key->last_used_at}{$key->last_used_at|date_format:"%Y-%m-%d %H:%M"}{else}<span style="color:#555;">Never</span>{/if}</td>
                                <td>{$key->total_requests|number_format}</td>
                                <td>
                                    {if $key->is_active}
                                        <span class="fps-keys-badge fps-keys-badge-active">Active</span>
                                    {else}
                                        <span class="fps-keys-badge fps-keys-badge-revoked">Revoked</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $key->is_active}
                                        <button class="fps-keys-btn fps-keys-btn-danger" onclick="FpsClientApi.revokeKey({$key->id})" title="Revoke this key">
                                            <i class="fas fa-ban"></i> Revoke
                                        </button>
                                    {else}
                                        <span style="color:#555;font-size:0.85rem;">--</span>
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            {else}
                <div class="fps-keys-empty">
                    <i class="fas fa-key"></i>
                    <p>You have no API keys yet. Create one above to get started.</p>
                </div>
            {/if}
        </div>

        {* === QUICK LINKS === *}
        <div class="fps-keys-section">
            <h2><i class="fas fa-external-link-alt"></i> Quick Links</h2>
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:40px;">
            <a href="index.php?m=fraud_prevention_suite&page=api-docs" class="fps-keys-btn fps-keys-btn-secondary">
                <i class="fas fa-book"></i> API Documentation
            </a>
            <a href="index.php?m=fraud_prevention_suite" class="fps-keys-btn fps-keys-btn-secondary">
                <i class="fas fa-shield-halved"></i> Overview
            </a>
        </div>
    {/if}

</div>

{literal}
<script>
var FpsClientApi = {
    baseUrl: window.location.pathname.replace(/\/[^\/]*$/, '') + '/index.php',

    createKey: function() {
        var btn = document.getElementById('fps-create-btn');
        var name = document.getElementById('fps-key-name').value.trim() || 'My API Key';
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
        FpsClientApi.clearAlert();

        var xhr = new XMLHttpRequest();
        xhr.open('POST', FpsClientApi.baseUrl + '?m=fraud_prevention_suite&ajax=1&action=client_create_api_key', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-key"></i> Generate Key';
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.error) {
                    FpsClientApi.showAlert('error', resp.error);
                    return;
                }
                if (resp.success && resp.api_key) {
                    var display = document.getElementById('fps-new-key-display');
                    var keyVal = document.getElementById('fps-new-key-value');
                    keyVal.textContent = resp.api_key;
                    display.classList.add('visible');
                    FpsClientApi.showAlert('success', resp.message || 'API key created successfully.');
                    // Reload after short delay to show new key in table
                    setTimeout(function() { location.reload(); }, 8000);
                }
            } catch(e) {
                FpsClientApi.showAlert('error', 'Unexpected response from server.');
            }
        };
        xhr.onerror = function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-key"></i> Generate Key';
            FpsClientApi.showAlert('error', 'Network error. Please try again.');
        };
{/literal}
        xhr.send('key_name=' + encodeURIComponent(name) + '&email=' + encodeURIComponent('{$client_email}'));
{literal}
    },

    revokeKey: function(keyId) {
        if (!confirm('Are you sure you want to revoke this API key? This action cannot be undone.')) return;

        var xhr = new XMLHttpRequest();
        xhr.open('POST', FpsClientApi.baseUrl + '?m=fraud_prevention_suite&ajax=1&action=client_revoke_api_key', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.error) {
                    FpsClientApi.showAlert('error', resp.error);
                    return;
                }
                if (resp.success) {
                    FpsClientApi.showAlert('success', resp.message || 'Key revoked.');
                    setTimeout(function() { location.reload(); }, 1500);
                }
            } catch(e) {
                FpsClientApi.showAlert('error', 'Unexpected response from server.');
            }
        };
        xhr.onerror = function() {
            FpsClientApi.showAlert('error', 'Network error. Please try again.');
        };
{/literal}
        xhr.send('key_id=' + keyId + '&email=' + encodeURIComponent('{$client_email}'));
{literal}
    },

    copyNewKey: function() {
        var keyVal = document.getElementById('fps-new-key-value');
        if (navigator.clipboard && keyVal) {
            navigator.clipboard.writeText(keyVal.textContent).then(function() {
                FpsClientApi.showAlert('success', 'API key copied to clipboard!');
            });
        }
    },

    copyPrefix: function(prefix) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(prefix).then(function() {
                FpsClientApi.showAlert('success', 'Key prefix "' + prefix + '" copied.');
            });
        }
    },

    showAlert: function(type, msg) {
        var el = document.getElementById('fps-key-alert');
        el.className = 'fps-keys-alert fps-keys-alert-' + type;
        el.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + msg;
        el.style.display = 'block';
    },

    clearAlert: function() {
        var el = document.getElementById('fps-key-alert');
        el.style.display = 'none';
        el.className = 'fps-keys-alert';
    }
};
</script>
{/literal}
