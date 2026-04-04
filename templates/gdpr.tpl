{literal}
<style>
/* EVPS 1000X Light Palette for GDPR Page */
:root {
  --fps-pub-bg: #f8fafc;
  --fps-pub-text: #334155;
  --fps-pub-text-secondary: #475569;
  --fps-pub-text-muted: #64748b;
  --fps-pub-card-bg: #ffffff;
  --fps-pub-card-border: #e2e8f0;
  --fps-pub-card-shadow: rgba(15,23,42,0.06);
  --fps-pub-input-bg: #ffffff;
  --fps-pub-input-border: #cbd5e1;
  --fps-pub-table-header: #f8fafc;
  --fps-pub-table-border: #f1f5f9;
  --fps-pub-code-bg: #0f172a;
  --fps-pub-code-text: #e2e8f0;
}
.fps-gdpr{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:inherit;line-height:1.7;max-width:800px;margin:0 auto;}
.fps-gdpr *{box-sizing:border-box;}
.fps-gdpr-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#2563eb 150%);color:#fff;padding:40px 30px;text-align:center;border-radius:20px;margin-bottom:32px;box-shadow:0 12px 40px rgba(15,23,42,0.12);}
.fps-gdpr-hero h1{font-size:2rem;font-weight:800;margin:0 0 12px;}
.fps-gdpr-hero p{font-size:1.05rem;color:#e2e8f0;margin:0 auto;max-width:600px;}
.fps-gdpr-nav{display:flex;gap:10px;justify-content:center;margin-bottom:32px;flex-wrap:wrap;}
.fps-gdpr-nav a{padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.9rem;border:1px solid var(--fps-pub-card-border);color:#2563eb;background:var(--fps-pub-card-bg);transition:all 0.2s;}
.fps-gdpr-nav a:hover,.fps-gdpr-nav a.active{background:#2563eb;color:#fff;border-color:#2563eb;}
.fps-gdpr-form{background:var(--fps-pub-card-bg);border-radius:12px;padding:32px;box-shadow:0 4px 16px var(--fps-pub-card-shadow);border:1px solid var(--fps-pub-card-border);margin-bottom:24px;}
.fps-gdpr-form h2{font-size:1.3rem;font-weight:700;margin:0 0 8px;}
.fps-gdpr-form p{font-size:0.95rem;color:var(--fps-pub-text-secondary);margin:0 0 20px;}
.fps-gdpr-field{margin-bottom:16px;}
.fps-gdpr-field label{display:block;font-weight:600;font-size:0.9rem;margin-bottom:4px;}
.fps-gdpr-field input,.fps-gdpr-field textarea{width:100%;padding:10px 14px;border:1px solid var(--fps-pub-input-border);border-radius:8px;font-size:0.95rem;font-family:inherit;background:var(--fps-pub-input-bg);color:var(--fps-pub-text);}
.fps-gdpr-field textarea{resize:vertical;min-height:80px;}
.fps-gdpr-field small{display:block;margin-top:4px;font-size:0.82rem;color:var(--fps-pub-text-muted);}
.fps-gdpr-btn{padding:12px 28px;border:none;border-radius:8px;font-size:0.95rem;font-weight:700;cursor:pointer;transition:all 0.2s;}
.fps-gdpr-btn-primary{background:#2563eb;color:#fff;}
.fps-gdpr-btn-primary:hover{background:#5a6fd6;}
.fps-gdpr-btn:disabled{opacity:0.5;cursor:not-allowed;}
.fps-gdpr-msg{padding:16px;border-radius:8px;margin-top:16px;font-size:0.95rem;}
.fps-gdpr-msg.success{background:rgba(56,239,125,0.08);border:1px solid rgba(56,239,125,0.2);color:#11998e;}
.fps-gdpr-msg.error{background:rgba(235,51,73,0.08);border:1px solid rgba(235,51,73,0.2);color:#eb3349;}
.fps-gdpr-info{background:var(--fps-pub-card-bg);border-radius:12px;padding:28px;box-shadow:0 2px 8px var(--fps-pub-card-shadow);border:1px solid var(--fps-pub-card-border);margin-bottom:24px;}
.fps-gdpr-info h3{font-size:1.1rem;font-weight:700;margin:0 0 12px;}
.fps-gdpr-info p{font-size:0.9rem;color:var(--fps-pub-text-secondary);margin:0 0 8px;}
.fps-gdpr-info ul{margin:8px 0;padding-left:20px;font-size:0.9rem;color:var(--fps-pub-text-secondary);}
</style>
<!-- Dark mode removed - using EVPS light theme -->
{/literal}

<div class="fps-gdpr">

    <div class="fps-gdpr-hero">
        <h1><i class="fas fa-user-shield"></i> Data Removal Request</h1>
        <p>Request removal of your data from our fraud prevention system. GDPR Article 17 -- Right to Erasure.</p>
        <div style="margin-top:16px;">
        </div>
    </div>

    <div class="fps-gdpr-nav">
        <a href="{$overview_url}"><i class="fas fa-home"></i> Overview</a>
        <a href="{$global_url}"><i class="fas fa-earth-americas"></i> Global Intel</a>
        <a href="{$api_docs_url}"><i class="fas fa-code"></i> API Docs</a>
        <a href="{$gdpr_url}" class="active"><i class="fas fa-user-shield"></i> Data Removal</a>
    </div>

    <div class="fps-gdpr-form">
        <h2><i class="fas fa-eraser"></i> Submit Removal Request</h2>
        <p>If you believe your data is stored in our fraud intelligence database and you want it removed, submit a request below. You will receive a verification email to confirm your identity.</p>

        <div class="fps-gdpr-field">
            <label for="fps-gdpr-email"><i class="fas fa-envelope"></i> Email Address *</label>
            <input type="email" id="fps-gdpr-email" placeholder="your@email.com" required>
            <small>The email address you want removed from the fraud database.</small>
        </div>

        <div class="fps-gdpr-field">
            <label for="fps-gdpr-name"><i class="fas fa-user"></i> Full Name</label>
            <input type="text" id="fps-gdpr-name" placeholder="John Doe">
            <small>Optional -- helps us locate all related records.</small>
        </div>

        <div class="fps-gdpr-field">
            <label for="fps-gdpr-reason"><i class="fas fa-comment"></i> Reason for Removal</label>
            <textarea id="fps-gdpr-reason" placeholder="e.g., I was incorrectly flagged as a bot account, I am a legitimate customer..."></textarea>
            <small>Optional -- helps the admin review your request faster.</small>
        </div>

        <button class="fps-gdpr-btn fps-gdpr-btn-primary" id="fps-gdpr-submit" onclick="fpsGdprSubmit()">
            <i class="fas fa-paper-plane"></i> Submit Request
        </button>

        <div id="fps-gdpr-message" style="display:none;"></div>
    </div>

    <div class="fps-gdpr-info">
        <h3><i class="fas fa-info-circle"></i> How This Works</h3>
        <ol>
            <li><strong>Submit your request</strong> with your email address above.</li>
            <li><strong>Verify your email</strong> -- we'll send a verification link to confirm you own the address.</li>
            <li><strong>Admin reviews</strong> your request and verifies your identity.</li>
            <li><strong>Data is purged</strong> from both our local database and the global intelligence hub.</li>
            <li>You receive confirmation that your data has been removed.</li>
        </ol>
        <p><strong>Timeline:</strong> Requests are typically processed within 72 hours of email verification.</p>
    </div>

    <div class="fps-gdpr-info">
        <h3><i class="fas fa-shield-halved"></i> What Data We Store</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <h4 style="color:#2563eb;margin:0 0 8px;font-size:0.95rem;">Stored (anonymized)</h4>
                <ul>
                    <li>SHA-256 hash of your email (irreversible)</li>
                    <li>IP address (if sharing enabled)</li>
                    <li>Country code</li>
                    <li>Risk score (0-100)</li>
                    <li>Boolean flags (VPN, Tor, proxy, etc.)</li>
                </ul>
            </div>
            <div>
                <h4 style="color:#eb3349;margin:0 0 8px;font-size:0.95rem;">Never Stored</h4>
                <ul>
                    <li>Your raw email address (only hash)</li>
                    <li>Names, phone numbers</li>
                    <li>Billing or payment details</li>
                    <li>Service or order information</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="fps-gdpr-info" style="background:rgba(102,126,234,0.03);border-color:rgba(102,126,234,0.15);">
        <h3><i class="fas fa-gavel"></i> Your Rights Under GDPR</h3>
        <ul>
            <li><strong>Article 15</strong> -- Right of Access: You can request what data we hold about you.</li>
            <li><strong>Article 17</strong> -- Right to Erasure: You can request deletion of your data (this form).</li>
            <li><strong>Article 20</strong> -- Right to Data Portability: You can request your data in a portable format.</li>
            <li><strong>Article 21</strong> -- Right to Object: You can object to processing of your data.</li>
        </ul>
        <p style="margin-top:8px;">For questions about data processing, contact the system administrator.</p>
    </div>

</div>

{literal}
<script>
function fpsGdprSubmit() {
    var email = document.getElementById('fps-gdpr-email').value.trim();
    var name = document.getElementById('fps-gdpr-name').value.trim();
    var reason = document.getElementById('fps-gdpr-reason').value.trim();
    var btn = document.getElementById('fps-gdpr-submit');
    var msg = document.getElementById('fps-gdpr-message');

    if (!email || email.indexOf('@') === -1) {
        msg.className = 'fps-gdpr-msg error';
        msg.textContent = 'Please enter a valid email address.';
        msg.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    var body = 'email=' + encodeURIComponent(email) + '&name=' + encodeURIComponent(name) + '&reason=' + encodeURIComponent(reason);

    fetch('index.php?m=fraud_prevention_suite&page=gdpr-submit&ajax=1', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';

        if (data.error) {
            msg.className = 'fps-gdpr-msg error';
            msg.textContent = data.error;
        } else {
            msg.className = 'fps-gdpr-msg success';
            msg.innerHTML = '<i class="fas fa-check-circle"></i> ' + (data.message || 'Request submitted.');
        }
        msg.style.display = 'block';
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        msg.className = 'fps-gdpr-msg error';
        msg.textContent = 'Network error. Please try again.';
        msg.style.display = 'block';
    });
}
</script>
{/literal}
