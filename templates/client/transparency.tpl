{literal}
<style>
/* FPS Transparency Page -- Client-facing, clean and reassuring */
:root {
  --fps-tp-bg: #f8fafc;
  --fps-tp-card: #ffffff;
  --fps-tp-border: #e2e8f0;
  --fps-tp-text: #334155;
  --fps-tp-muted: #64748b;
  --fps-tp-green: #16a34a;
  --fps-tp-yellow: #d97706;
  --fps-tp-red: #dc2626;
  --fps-tp-blue: #2563eb;
}
.fps-tp{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:var(--fps-tp-text);line-height:1.7;max-width:800px;margin:0 auto;padding:20px 0;}
.fps-tp *{box-sizing:border-box;}

.fps-tp-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#16a34a 150%);color:#fff;padding:40px 30px;text-align:center;border-radius:16px;margin-bottom:28px;position:relative;overflow:hidden;}
.fps-tp-hero h1{font-size:1.8rem;font-weight:800;margin:0 0 8px;letter-spacing:-0.3px;}
.fps-tp-hero h1 i{color:#4ade80;margin-right:8px;}
.fps-tp-hero p{font-size:1rem;color:#e2e8f0;margin:0;opacity:0.9;}

.fps-tp-card{background:var(--fps-tp-card);border:1px solid var(--fps-tp-border);border-radius:14px;padding:28px;margin-bottom:20px;box-shadow:0 2px 8px rgba(15,23,42,0.04);}
.fps-tp-card h2{font-size:1.2rem;font-weight:700;margin:0 0 16px;color:#0f172a;display:flex;align-items:center;gap:10px;}
.fps-tp-card h2 i{color:var(--fps-tp-green);width:24px;text-align:center;}

/* Trust badge */
.fps-tp-trust{display:flex;align-items:center;gap:20px;padding:20px;border-radius:12px;margin-bottom:20px;}
.fps-tp-trust.trusted{background:#f0fdf4;border:1px solid #bbf7d0;}
.fps-tp-trust.normal{background:#f0f9ff;border:1px solid #bae6fd;}
.fps-tp-trust.suspended{background:#fef2f2;border:1px solid #fecaca;}
.fps-tp-trust.blacklisted{background:#fef2f2;border:1px solid #fecaca;}
.fps-tp-trust-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0;}
.fps-tp-trust.trusted .fps-tp-trust-icon{background:#dcfce7;color:#16a34a;}
.fps-tp-trust.normal .fps-tp-trust-icon{background:#dbeafe;color:#2563eb;}
.fps-tp-trust.suspended .fps-tp-trust-icon{background:#fee2e2;color:#dc2626;}
.fps-tp-trust.blacklisted .fps-tp-trust-icon{background:#fee2e2;color:#dc2626;}
.fps-tp-trust-info h3{margin:0 0 4px;font-size:1.1rem;font-weight:700;color:#0f172a;}
.fps-tp-trust-info p{margin:0;font-size:0.9rem;color:var(--fps-tp-muted);}

/* Stats grid */
.fps-tp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:20px;}
.fps-tp-stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px 16px;text-align:center;}
.fps-tp-stat-value{font-size:1.6rem;font-weight:800;color:#0f172a;display:block;}
.fps-tp-stat-label{font-size:0.78rem;color:var(--fps-tp-muted);text-transform:uppercase;letter-spacing:0.4px;font-weight:600;margin-top:4px;display:block;}

/* Risk indicator */
.fps-tp-risk{display:inline-flex;align-items:center;gap:6px;padding:6px 16px;border-radius:20px;font-size:0.88rem;font-weight:700;}
.fps-tp-risk.low{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;}
.fps-tp-risk.medium{background:#fffbeb;color:#d97706;border:1px solid #fde68a;}
.fps-tp-risk.high{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;}

/* Reasons list */
.fps-tp-reasons{list-style:none;padding:0;margin:0;}
.fps-tp-reasons li{padding:10px 14px;border-bottom:1px solid #f1f5f9;font-size:0.92rem;display:flex;align-items:center;gap:10px;color:#475569;}
.fps-tp-reasons li:last-child{border-bottom:none;}
.fps-tp-reasons li i{color:#d97706;width:18px;text-align:center;flex-shrink:0;}
.fps-tp-no-flags{padding:16px;text-align:center;color:#16a34a;font-size:0.95rem;}
.fps-tp-no-flags i{margin-right:6px;}

/* Action buttons */
.fps-tp-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px;}
.fps-tp-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:10px;font-size:0.92rem;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:all 0.2s;}
.fps-tp-btn-primary{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;box-shadow:0 4px 12px rgba(22,163,74,0.2);}
.fps-tp-btn-primary:hover{box-shadow:0 6px 18px rgba(22,163,74,0.3);transform:translateY(-1px);}
.fps-tp-btn-outline{background:#fff;color:#334155;border:2px solid #e2e8f0;}
.fps-tp-btn-outline:hover{border-color:#2563eb;color:#2563eb;}
.fps-tp-btn:disabled{opacity:0.6;cursor:not-allowed;transform:none !important;}

/* Login prompt */
.fps-tp-login-prompt{text-align:center;padding:40px 20px;}
.fps-tp-login-prompt i{font-size:3rem;color:#94a3b8;margin-bottom:16px;display:block;}
.fps-tp-login-prompt p{font-size:1rem;color:var(--fps-tp-muted);margin:0 0 20px;}

/* Notification */
.fps-tp-notice{padding:14px 20px;border-radius:10px;font-size:0.9rem;margin-top:16px;display:none;}
.fps-tp-notice.success{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;display:block;}
.fps-tp-notice.error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;display:block;}

/* Footer note */
.fps-tp-footer{text-align:center;padding:20px;font-size:0.82rem;color:#94a3b8;}
.fps-tp-footer a{color:#2563eb;text-decoration:none;font-weight:600;}
.fps-tp-footer a:hover{text-decoration:underline;}
</style>
{/literal}

<div class="fps-tp">
  <div class="fps-tp-hero">
    <h1><i class="fas fa-shield-halved"></i> Account Security Status</h1>
    <p>Review your account's security profile and trust status</p>
  </div>

  {if $logged_in}
    {* Trust Status Banner *}
    <div class="fps-tp-trust {$trust_status}">
      <div class="fps-tp-trust-icon">
        {if $trust_status == 'trusted'}
          <i class="fas fa-check-circle"></i>
        {elseif $trust_status == 'suspended'}
          <i class="fas fa-exclamation-triangle"></i>
        {elseif $trust_status == 'blacklisted'}
          <i class="fas fa-ban"></i>
        {else}
          <i class="fas fa-user-shield"></i>
        {/if}
      </div>
      <div class="fps-tp-trust-info">
        {if $trust_status == 'trusted'}
          <h3>Trusted Account</h3>
          <p>Your account has been verified and is in good standing. Enjoy streamlined processing.</p>
        {elseif $trust_status == 'suspended'}
          <h3>Account Under Review</h3>
          <p>Your account has been flagged for review. If you believe this is incorrect, please request a manual review below.</p>
        {elseif $trust_status == 'blacklisted'}
          <h3>Account Restricted</h3>
          <p>Your account has been restricted. Please contact support for assistance.</p>
        {else}
          <h3>Standard Account</h3>
          <p>Your account is active with standard verification. Continue using our services to build your trust score.</p>
        {/if}
      </div>
    </div>

    {* Stats Cards *}
    <div class="fps-tp-stats">
      <div class="fps-tp-stat">
        <span class="fps-tp-stat-value">{$checks_count}</span>
        <span class="fps-tp-stat-label">Security Checks</span>
      </div>
      <div class="fps-tp-stat">
        <span class="fps-tp-stat-value">
          <span class="fps-tp-risk {$risk_level}">
            {if $risk_level == 'low'}
              <i class="fas fa-check-circle"></i> Low
            {elseif $risk_level == 'medium'}
              <i class="fas fa-exclamation-circle"></i> Medium
            {else}
              <i class="fas fa-times-circle"></i> High
            {/if}
          </span>
        </span>
        <span class="fps-tp-stat-label">Current Risk Level</span>
      </div>
      <div class="fps-tp-stat">
        <span class="fps-tp-stat-value" style="font-size:0.95rem;">{if $last_check}{$last_check}{else}Never{/if}</span>
        <span class="fps-tp-stat-label">Last Check</span>
      </div>
    </div>

    {* Flagged Reasons *}
    <div class="fps-tp-card">
      <h2><i class="fas fa-flag"></i> Security Findings</h2>
      <p style="font-size:0.9rem;color:#64748b;margin:0 0 16px;">
        These are the areas our security system has reviewed on your account.
        Not all findings indicate a problem -- they are part of routine verification.
      </p>
      {if $flagged_reasons && count($flagged_reasons) > 0}
        <ul class="fps-tp-reasons">
          {foreach from=$flagged_reasons item=reason}
            <li><i class="fas fa-info-circle"></i> {$reason}</li>
          {/foreach}
        </ul>
      {else}
        <div class="fps-tp-no-flags">
          <i class="fas fa-check-double"></i> No security concerns found. Your account looks great!
        </div>
      {/if}
    </div>

    {* Actions *}
    <div class="fps-tp-card">
      <h2><i class="fas fa-hand-holding-heart"></i> Your Options</h2>
      <p style="font-size:0.9rem;color:#64748b;margin:0 0 16px;">
        If you believe any finding is incorrect, you can request a manual review.
        You can also access your data rights under GDPR/privacy regulations.
      </p>
      <div class="fps-tp-actions">
        <button type="button" class="fps-tp-btn fps-tp-btn-primary" id="fps-tp-review-btn" onclick="fpsTransparency.requestReview()">
          <i class="fas fa-clipboard-check"></i> Request Manual Review
        </button>
        <a href="{$gdpr_export_url}" class="fps-tp-btn fps-tp-btn-outline">
          <i class="fas fa-download"></i> Data Export / GDPR
        </a>
      </div>
      <div id="fps-tp-notice" class="fps-tp-notice"></div>
    </div>

  {else}
    {* Not logged in *}
    <div class="fps-tp-card">
      <div class="fps-tp-login-prompt">
        <i class="fas fa-lock"></i>
        <p>Please log in to view your account security status.</p>
        <a href="clientarea.php" class="fps-tp-btn fps-tp-btn-primary">
          <i class="fas fa-sign-in-alt"></i> Log In
        </a>
      </div>
    </div>
  {/if}

  <div class="fps-tp-footer">
    Powered by <a href="{$overview_url}">Fraud Prevention Suite</a> v{$module_version} |
    <a href="{$gdpr_export_url}">Privacy & Data Rights</a>
  </div>
</div>

{if $logged_in}
{literal}
<script>
(function(){
  var ajaxUrl = '{/literal}{$ajax_url}{literal}';

  window.fpsTransparency = {
    requestReview: function() {
      var btn = document.getElementById('fps-tp-review-btn');
      var notice = document.getElementById('fps-tp-notice');
      if (!btn) return;

      btn.disabled = true;
      btn.textContent = 'Submitting...';
      notice.className = 'fps-tp-notice';
      notice.style.display = 'none';

      fetch(ajaxUrl + '&a=request_manual_review', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        credentials: 'same-origin',
        body: ''
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          notice.className = 'fps-tp-notice success';
          notice.style.display = 'block';
          var ticketRef = data.ticket_id ? ' (Ticket #' + data.ticket_id + ')' : '';
          notice.textContent = 'Review request submitted successfully!' + ticketRef + ' Our team will review your account and reach out if needed.';
          btn.textContent = 'Review Requested';
        } else {
          notice.className = 'fps-tp-notice error';
          notice.style.display = 'block';
          notice.textContent = data.error || 'Something went wrong. Please try again or contact support directly.';
          btn.disabled = false;
          btn.textContent = 'Request Manual Review';
          var icon = document.createElement('i');
          icon.className = 'fas fa-clipboard-check';
          icon.style.marginRight = '8px';
          btn.prepend(icon);
        }
      })
      .catch(function(err) {
        notice.className = 'fps-tp-notice error';
        notice.style.display = 'block';
        notice.textContent = 'Network error. Please try again.';
        btn.disabled = false;
        btn.textContent = 'Request Manual Review';
      });
    }
  };
})();
</script>
{/literal}
{/if}
