<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * TabReviewQueue -- high/critical risk orders awaiting admin review.
 *
 * Displays filterable table with approve/deny action buttons per row,
 * bulk action bar, pagination, per-check admin assignment, and notes.
 */
class TabReviewQueue
{
    public function render(array $vars, string $modulelink): void
    {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        // Filters
        $filterLevel    = $_GET['risk_level'] ?? '';
        $filterSearch   = $_GET['search'] ?? '';
        $filterFrom     = $_GET['date_from'] ?? '';
        $filterTo       = $_GET['date_to'] ?? '';
        $filterAssigned = $_GET['assigned_to'] ?? '';

        // Check if assignment columns exist
        $hasAssignCol = Capsule::schema()->hasColumn('mod_fps_checks', 'assigned_to');
        $hasNotesCol  = Capsule::schema()->hasColumn('mod_fps_checks', 'admin_notes');

        // Batch-fetch admins for assignment dropdown + display
        $adminList = [];
        try {
            Capsule::table('tbladmins')
                ->where('disabled', 0)
                ->get(['id', 'firstname', 'lastname'])
                ->each(function ($a) use (&$adminList) {
                    $adminList[(int)$a->id] = trim($a->firstname . ' ' . $a->lastname);
                });
        } catch (\Throwable $e) {
            // Non-fatal -- admin list unavailable
        }

        $this->fpsRenderFilterBar($modulelink, $filterLevel, $filterSearch, $filterFrom, $filterTo, $filterAssigned, $adminList, $hasAssignCol);

        try {
            // Show unreviewed checks from automated sources (new signups/orders)
            // Exclude manual re-scans and validation tests - those go to client profile
            $filterType = $_GET['check_type'] ?? 'auto_only';
            $query = Capsule::table('mod_fps_checks')
                ->whereNull('reviewed_by');

            if ($filterType === 'auto_only') {
                // Default: only show automated checks (new signups, new orders, bot blocks)
                $query->whereIn('check_type', ['pre_checkout', 'auto', 'bot_signup_block', 'bot_detection']);
            }
            // 'all' shows everything including manual re-scans

            if ($filterLevel !== '' && in_array($filterLevel, ['low', 'medium', 'high', 'critical'], true)) {
                $query->where('risk_level', $filterLevel);
            }
            if ($filterSearch !== '') {
                $query->where(function ($q) use ($filterSearch) {
                    $q->where('email', 'LIKE', '%' . $filterSearch . '%')
                      ->orWhere('ip_address', 'LIKE', '%' . $filterSearch . '%')
                      ->orWhere('order_id', '=', (int)$filterSearch);
                });
            }
            if ($filterFrom !== '') {
                $query->where('created_at', '>=', $filterFrom . ' 00:00:00');
            }
            if ($filterTo !== '') {
                $query->where('created_at', '<=', $filterTo . ' 23:59:59');
            }
            // Filter by assigned admin
            if ($hasAssignCol && $filterAssigned !== '') {
                if ($filterAssigned === 'unassigned') {
                    $query->whereNull('assigned_to');
                } elseif ($filterAssigned === 'mine') {
                    $myAdminId = (int)($_SESSION['adminid'] ?? 0);
                    if ($myAdminId > 0) {
                        $query->where('assigned_to', $myAdminId);
                    }
                } elseif (ctype_digit($filterAssigned)) {
                    $query->where('assigned_to', (int)$filterAssigned);
                }
            }

            $total      = $query->count();
            $totalPages = max(1, (int)ceil($total / $perPage));

            $checks = $query->orderByDesc('risk_score')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            // ---- Batch-fetch client names (single query, avoids N+1) ----
            $clientIds = $checks->pluck('client_id')
                ->filter(fn($id) => (int)$id > 0)
                ->unique()
                ->values()
                ->toArray();

            $clientMap = [];
            if (!empty($clientIds)) {
                Capsule::table('tblclients')
                    ->whereIn('id', $clientIds)
                    ->get(['id', 'firstname', 'lastname', 'email'])
                    ->each(function ($c) use (&$clientMap) {
                        $clientMap[(int)$c->id] = $c;
                    });
            }

            // ---- Queue count badge ----
            $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

            echo '<div class="fps-queue-badge-bar fps-queue-header-row">';
            echo '  <span class="fps-badge fps-badge-high"><i class="fas fa-clock"></i> '
                . htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8')
                . ' checks pending review</span>';
            echo '  <button type="button" class="fps-btn fps-btn-sm fps-btn-warning" style="margin-left:auto;"'
                . ' onclick="FpsAdmin.bulkAction(\'archive_guest\', \'' . $ajaxUrl . '\')"'
                . ' title="Archive all guest pre-checkout entries that have no associated client account">'
                . '<i class="fas fa-archive"></i> Archive Guest Checks</button>';
            echo '</div>';

            // ---- Bulk actions bar ----
            echo '<div class="fps-bulk-actions-bar">';
            echo '  <label class="fps-checkbox-label">';
            echo '    <input type="checkbox" id="fps-select-all-queue" onclick="FpsAdmin.toggleSelectAll(\'fps-queue-check\')">';
            echo '    <span>Select All</span>';
            echo '  </label>';
            echo '  <button type="button" class="fps-btn fps-btn-sm fps-btn-success" onclick="FpsAdmin.bulkAction(\'approve\', \'' . $ajaxUrl . '\')">';
            echo '    <i class="fas fa-check"></i> Bulk Approve';
            echo '  </button>';
            echo '  <button type="button" class="fps-btn fps-btn-sm fps-btn-danger" onclick="FpsAdmin.bulkAction(\'deny\', \'' . $ajaxUrl . '\')">';
            echo '    <i class="fas fa-times"></i> Bulk Deny';
            echo '  </button>';
            echo '</div>';

            // ---- Build table rows ----
            $headers = ['', 'Client', 'Email', 'Type', 'Order', 'Risk Score', 'IP', 'Country', 'Assigned', 'Time', 'Actions'];
            $rows = [];

            foreach ($checks as $check) {
                $checkIdSafe  = (int)$check->id;
                $clientIdSafe = (int)$check->client_id;
                $orderIdSafe  = (int)$check->order_id;
                $checkType    = $check->check_type ?? 'auto';
                $isGuest      = ($clientIdSafe === 0);
                $client       = $clientMap[$clientIdSafe] ?? null;
                $clientExists = ($client !== null);

                // ---- Client cell ----
                $checkEmail = htmlspecialchars($check->email ?? '', ENT_QUOTES, 'UTF-8');
                if ($isGuest) {
                    // No client yet -- pre-checkout visitor
                    $clientName = '<span class="fps-queue-guest-cell">'
                        . '<span class="fps-badge fps-badge-pending" style="font-size:0.68rem;">'
                        . '<i class="fas fa-user-clock"></i> Guest</span>'
                        . '<span class="fps-queue-guest-note">Pre-checkout visitor</span>'
                        . '</span>';
                } elseif (!$clientExists) {
                    // Client was deleted after the check was recorded
                    $clientName = '<span class="fps-queue-deleted-cell">'
                        . '<i class="fas fa-user-slash fps-text-muted"></i>'
                        . ' <span class="fps-text-muted fps-mono" style="font-size:0.8rem;">Deleted #' . $clientIdSafe . '</span>'
                        . '</span>';
                } else {
                    $name = trim($client->firstname . ' ' . $client->lastname);
                    $displayName = $name !== '' ? $name : 'Client #' . $clientIdSafe;
                    $profileUrl  = htmlspecialchars(
                        $modulelink . '&tab=client_profile&client_id=' . $clientIdSafe,
                        ENT_QUOTES, 'UTF-8'
                    );
                    $clientName  = '<div class="fps-queue-client-cell">'
                        . '<a href="' . $profileUrl . '" class="fps-queue-client-name">'
                        . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</a>'
                        . '<span class="fps-queue-client-id fps-mono">#' . $clientIdSafe . '</span>'
                        . '</div>';
                }

                // Use client's confirmed email if available, otherwise the check email
                $displayEmail = ($clientExists && !empty($client->email))
                    ? htmlspecialchars($client->email, ENT_QUOTES, 'UTF-8')
                    : $checkEmail;

                // ---- Check type badge ----
                $typeLabels = [
                    'pre_checkout'     => ['fps-badge-pending',  'fa-cart-shopping', 'Pre-checkout'],
                    'auto'             => ['fps-badge-info',     'fa-bolt',          'New Order'],
                    'registration'     => ['fps-badge-info',     'fa-user-plus',     'Registration'],
                    'bot_signup_block' => ['fps-badge-blocked',  'fa-robot',         'Bot Block'],
                    'bot_detection'    => ['fps-badge-blocked',  'fa-robot',         'Bot'],
                    'turnstile_block'  => ['fps-badge-critical', 'fa-shield-halved', 'Turnstile'],
                    'manual'           => ['fps-scan-badge-none','fa-hand',          'Manual'],
                    'engine_validation'=> ['fps-scan-badge-none','fa-flask',         'Test'],
                ];
                [$typeBadgeCls, $typeIcon, $typeLabel] = $typeLabels[$checkType]
                    ?? ['fps-scan-badge-none', 'fa-question-circle', ucfirst($checkType)];
                $typeBadge = '<span class="fps-badge ' . $typeBadgeCls . '" style="font-size:0.68rem;">'
                    . '<i class="fas ' . $typeIcon . '"></i> ' . $typeLabel . '</span>';

                // ---- Order cell ----
                $orderCell = ($orderIdSafe > 0)
                    ? '<a href="orders.php?action=view&id=' . $orderIdSafe . '" class="fps-mono fps-text-muted" style="font-size:0.85rem;">#' . $orderIdSafe . '</a>'
                    : '<span class="fps-text-muted">---</span>';

                // ---- Risk badge ----
                $badge = FpsAdminRenderer::renderBadge($check->risk_level, (float)$check->risk_score);

                // ---- IP / Country ----
                $ip      = htmlspecialchars($check->ip_address ?? '---', ENT_QUOTES, 'UTF-8');
                $country = htmlspecialchars($check->country ?? '---', ENT_QUOTES, 'UTF-8');
                $time    = htmlspecialchars($check->created_at ?? '', ENT_QUOTES, 'UTF-8');

                // ---- Assignment cell ----
                $assignedCell = $this->fpsRenderAssignmentCell($checkIdSafe, $check, $adminList, $hasAssignCol, $ajaxUrl);

                // ---- Actions (with notes button) ----
                $actions = '<div class="fps-action-group">';
                $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-success"'
                    . ' onclick="FpsAdmin.approveCheck(' . $checkIdSafe . ', \'' . $ajaxUrl . '\')" title="Approve -- mark reviewed, allow">'
                    . '<i class="fas fa-check"></i></button>';
                $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-danger"'
                    . ' onclick="FpsAdmin.denyCheck(' . $checkIdSafe . ', \'' . $ajaxUrl . '\')" title="Deny -- mark reviewed, block">'
                    . '<i class="fas fa-times"></i></button>';

                if (!$isGuest && $clientExists) {
                    // Real client -- show profile link
                    $profileUrl = htmlspecialchars($modulelink . '&tab=client_profile&client_id=' . $clientIdSafe, ENT_QUOTES, 'UTF-8');
                    $actions .= '<a href="' . $profileUrl . '" class="fps-btn fps-btn-xs fps-btn-info" title="View FPS Client Profile">'
                        . '<i class="fas fa-user-shield"></i></a>';
                } else {
                    // Guest or deleted -- search by email instead
                    $emailSearch = urlencode($check->email ?? '');
                    $actions .= '<a href="clients.php?search=' . $emailSearch . '" class="fps-btn fps-btn-xs fps-btn-secondary" title="Search WHMCS for this email">'
                        . '<i class="fas fa-search"></i></a>';
                }

                $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-warning"'
                    . ' onclick="FpsAdmin.reportToFraudRecord(' . $checkIdSafe . ', \'' . $ajaxUrl . '\')" title="Report IP/email to FraudRecord">'
                    . '<i class="fas fa-flag"></i></button>';

                // Notes button
                if ($hasNotesCol) {
                    $noteCount = 0;
                    $rawNotes = $check->admin_notes ?? '';
                    if (!empty($rawNotes)) {
                        $decoded = json_decode($rawNotes, true);
                        if (is_array($decoded)) {
                            $noteCount = count($decoded);
                        }
                    }
                    $noteBadge = $noteCount > 0
                        ? '<span style="background:#667eea;color:#fff;border-radius:50%;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;font-size:0.65rem;margin-left:2px;">' . $noteCount . '</span>'
                        : '';
                    $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-secondary"'
                        . ' onclick="fpsQueueNotes.open(' . $checkIdSafe . ')" title="View/Add Notes">'
                        . '<i class="fas fa-sticky-note"></i>' . $noteBadge . '</button>';
                }

                $actions .= '</div>';

                $rows[] = [
                    '<input type="checkbox" class="fps-queue-check" value="' . $checkIdSafe . '">',
                    $clientName,
                    $displayEmail,
                    $typeBadge,
                    $orderCell,
                    $badge,
                    $ip,
                    $country,
                    $assignedCell,
                    $time,
                    $actions,
                ];
            }

            echo FpsAdminRenderer::renderTable($headers, $rows, 'fps-review-queue-table');

            // Pagination
            $paginationBase = $modulelink . '&tab=review_queue';
            if ($filterLevel !== '') {
                $paginationBase .= '&risk_level=' . urlencode($filterLevel);
            }
            if ($filterSearch !== '') {
                $paginationBase .= '&search=' . urlencode($filterSearch);
            }
            if ($filterAssigned !== '') {
                $paginationBase .= '&assigned_to=' . urlencode($filterAssigned);
            }
            echo FpsAdminRenderer::renderPagination($page, $totalPages, $paginationBase);

        } catch (\Throwable $e) {
            echo '<div class="fps-alert fps-alert-danger">';
            echo '<i class="fas fa-exclamation-circle"></i> Error loading review queue: ';
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            echo '</div>';
        }

        // Notes modal + assignment JS
        $this->fpsRenderNotesModal($modulelink);

        // Unscanned clients/users section
        $this->fpsRenderUnscannedSection($modulelink);
    }

    /**
     * Render the assignment cell for a single check row.
     */
    private function fpsRenderAssignmentCell(
        int $checkId,
        object $check,
        array $adminList,
        bool $hasAssignCol,
        string $ajaxUrl
    ): string {
        if (!$hasAssignCol) {
            return '<span class="fps-text-muted" style="font-size:0.8rem;">N/A</span>';
        }

        $assignedTo = (int)($check->assigned_to ?? 0);
        $assignedName = '';
        if ($assignedTo > 0 && isset($adminList[$assignedTo])) {
            $assignedName = $adminList[$assignedTo];
        }

        $html = '<div class="fps-assign-cell" style="position:relative;" id="fps-assign-' . $checkId . '">';

        if ($assignedTo > 0 && $assignedName !== '') {
            $html .= '<span class="fps-badge fps-badge-info" style="font-size:0.68rem;cursor:pointer;" '
                . 'onclick="fpsQueueAssign.toggle(' . $checkId . ')" title="Click to reassign">'
                . '<i class="fas fa-user-check"></i> ' . htmlspecialchars($assignedName, ENT_QUOTES, 'UTF-8')
                . '</span>';
        } else {
            $html .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-secondary" '
                . 'onclick="fpsQueueAssign.toggle(' . $checkId . ')" title="Assign to admin">'
                . '<i class="fas fa-user-plus"></i></button>';
        }

        // Hidden dropdown (toggled by JS)
        $html .= '<div class="fps-assign-dropdown" id="fps-assign-dd-' . $checkId . '" style="display:none;position:absolute;z-index:100;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.15);padding:6px 0;min-width:180px;margin-top:4px;">';
        $html .= '<a href="#" onclick="fpsQueueAssign.set(' . $checkId . ',0);return false;" '
            . 'style="display:block;padding:6px 14px;font-size:0.82rem;color:#666;text-decoration:none;">Unassign</a>';
        foreach ($adminList as $aId => $aName) {
            $selected = ($aId === $assignedTo) ? 'font-weight:700;color:#667eea;' : '';
            $html .= '<a href="#" onclick="fpsQueueAssign.set(' . $checkId . ',' . $aId . ');return false;" '
                . 'style="display:block;padding:6px 14px;font-size:0.82rem;text-decoration:none;' . $selected . '">'
                . htmlspecialchars($aName, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        $html .= '</div>';

        $html .= '</div>';
        return $html;
    }

    /**
     * Render the notes modal and inline JS for assignment/notes functionality.
     */
    private function fpsRenderNotesModal(string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');
        $csrfToken = function_exists('generate_token') ? generate_token('plain') : ($_SESSION['token'] ?? '');

        // Notes modal container
        echo '<div id="fps-notes-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;align-items:center;justify-content:center;">';
        echo '<div style="background:#fff;border-radius:14px;max-width:560px;width:90%;max-height:80vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.3);">';
        echo '<div style="padding:18px 24px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;display:flex;align-items:center;justify-content:space-between;">';
        echo '<h4 style="margin:0;font-size:1rem;"><i class="fas fa-sticky-note"></i> Check Notes <span id="fps-notes-check-id" style="opacity:0.7;font-size:0.85rem;"></span></h4>';
        echo '<button onclick="fpsQueueNotes.close()" style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer;opacity:0.8;"><i class="fas fa-times"></i></button>';
        echo '</div>';
        echo '<div id="fps-notes-list" style="flex:1;overflow-y:auto;padding:16px 24px;min-height:120px;">';
        echo '<div style="text-align:center;color:#999;padding:20px;">Loading...</div>';
        echo '</div>';
        echo '<div style="padding:14px 24px;border-top:1px solid #eee;display:flex;gap:10px;">';
        echo '<input type="text" id="fps-note-input" placeholder="Add a note..." style="flex:1;padding:10px 14px;border:1px solid #ddd;border-radius:8px;font-size:0.9rem;" onkeydown="if(event.key===\'Enter\')fpsQueueNotes.add()">';
        echo '<button onclick="fpsQueueNotes.add()" class="fps-btn fps-btn-sm fps-btn-primary" style="white-space:nowrap;"><i class="fas fa-paper-plane"></i> Add</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // JS for assignment and notes
        echo '<script>';
        echo '(function(){';
        echo 'var csrfToken=' . json_encode($csrfToken) . ';';
        echo 'var ajaxBase=' . json_encode($ajaxUrl) . ';';

        // Assignment toggle/set
        echo 'window.fpsQueueAssign={';
        echo 'toggle:function(id){';
        echo '  var dd=document.getElementById("fps-assign-dd-"+id);if(!dd)return;';
        echo '  document.querySelectorAll(".fps-assign-dropdown").forEach(function(el){if(el.id!=="fps-assign-dd-"+id)el.style.display="none";});';
        echo '  dd.style.display=dd.style.display==="none"?"block":"none";';
        echo '},';
        echo 'set:function(checkId,adminId){';
        echo '  var dd=document.getElementById("fps-assign-dd-"+checkId);if(dd)dd.style.display="none";';
        echo '  fetch(ajaxBase+"&a=assign_check",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},credentials:"same-origin",body:"token="+encodeURIComponent(csrfToken)+"&check_id="+checkId+"&admin_id="+adminId})';
        echo '  .then(function(r){return r.json();})';
        echo '  .then(function(data){';
        echo '    if(data.error){if(typeof FpsAdmin!=="undefined"&&FpsAdmin.showToast)FpsAdmin.showToast(data.error,"error");else alert(data.error);return;}';
        echo '    var cell=document.getElementById("fps-assign-"+checkId);if(!cell)return;';
        echo '    var trigger=cell.querySelector(".fps-badge,.fps-btn");if(!trigger)return;';
        echo '    if(adminId>0&&data.admin_name){';
        echo '      var el=document.createElement("span");el.className="fps-badge fps-badge-info";el.style.cssText="font-size:0.68rem;cursor:pointer;";';
        echo '      el.setAttribute("onclick","fpsQueueAssign.toggle("+checkId+")");el.setAttribute("title","Click to reassign");';
        echo '      el.textContent=data.admin_name;';
        echo '      var ic=document.createElement("i");ic.className="fas fa-user-check";ic.style.marginRight="4px";el.prepend(ic);';
        echo '      trigger.replaceWith(el);';
        echo '    }else{';
        echo '      var btn=document.createElement("button");btn.type="button";btn.className="fps-btn fps-btn-xs fps-btn-secondary";';
        echo '      btn.setAttribute("onclick","fpsQueueAssign.toggle("+checkId+")");btn.setAttribute("title","Assign to admin");';
        echo '      var ic=document.createElement("i");ic.className="fas fa-user-plus";btn.appendChild(ic);';
        echo '      trigger.replaceWith(btn);';
        echo '    }';
        echo '    if(typeof FpsAdmin!=="undefined"&&FpsAdmin.showToast)FpsAdmin.showToast(adminId>0?"Assigned to "+data.admin_name:"Unassigned","success");';
        echo '  }).catch(function(e){console.error("FPS assign error:",e);});';
        echo '}};';

        // Close dropdowns on outside click
        echo 'document.addEventListener("click",function(e){if(!e.target.closest(".fps-assign-cell"))document.querySelectorAll(".fps-assign-dropdown").forEach(function(el){el.style.display="none";});});';

        // Notes modal
        echo 'var currentCheckId=0;';
        echo 'window.fpsQueueNotes={';
        echo 'open:function(checkId){';
        echo '  currentCheckId=checkId;';
        echo '  document.getElementById("fps-notes-check-id").textContent="#"+checkId;';
        echo '  var modal=document.getElementById("fps-notes-modal");modal.style.display="flex";';
        echo '  document.getElementById("fps-note-input").value="";';
        echo '  var list=document.getElementById("fps-notes-list");';
        echo '  while(list.firstChild)list.removeChild(list.firstChild);';
        echo '  var ldiv=document.createElement("div");ldiv.style.cssText="text-align:center;color:#999;padding:20px;";ldiv.textContent="Loading...";list.appendChild(ldiv);';
        echo '  fetch(ajaxBase+"&a=get_check_notes&check_id="+checkId,{credentials:"same-origin"})';
        echo '  .then(function(r){return r.json();})';
        echo '  .then(function(data){fpsQueueNotes._render(data.notes||[]);})';
        echo '  .catch(function(){var el=document.getElementById("fps-notes-list");while(el.firstChild)el.removeChild(el.firstChild);var d=document.createElement("div");d.style.cssText="color:#e74c3c;padding:16px;";d.textContent="Failed to load notes";el.appendChild(d);});';
        echo '},';
        echo 'close:function(){document.getElementById("fps-notes-modal").style.display="none";currentCheckId=0;},';
        echo 'add:function(){';
        echo '  var input=document.getElementById("fps-note-input");var text=(input.value||"").trim();';
        echo '  if(!text||currentCheckId<1)return;';
        echo '  fetch(ajaxBase+"&a=add_check_note",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},credentials:"same-origin",body:"token="+encodeURIComponent(csrfToken)+"&check_id="+currentCheckId+"&note="+encodeURIComponent(text)})';
        echo '  .then(function(r){return r.json();})';
        echo '  .then(function(data){';
        echo '    if(data.error){if(typeof FpsAdmin!=="undefined"&&FpsAdmin.showToast)FpsAdmin.showToast(data.error,"error");return;}';
        echo '    input.value="";fpsQueueNotes._render(data.notes||[]);';
        echo '  }).catch(function(e){console.error("FPS note error:",e);});';
        echo '},';
        echo '_render:function(notes){';
        echo '  var c=document.getElementById("fps-notes-list");while(c.firstChild)c.removeChild(c.firstChild);';
        echo '  if(!notes||notes.length===0){var d=document.createElement("div");d.style.cssText="text-align:center;color:#999;padding:20px;font-size:0.9rem;";d.textContent="No notes yet. Add one below.";c.appendChild(d);return;}';
        echo '  notes.forEach(function(n){';
        echo '    var wrap=document.createElement("div");wrap.style.cssText="margin-bottom:12px;padding:10px 14px;background:#f8f9fc;border-radius:8px;border-left:3px solid #667eea;";';
        echo '    var hdr=document.createElement("div");hdr.style.cssText="display:flex;justify-content:space-between;margin-bottom:4px;";';
        echo '    var nm=document.createElement("strong");nm.style.cssText="font-size:0.82rem;color:#333;";nm.textContent=(n.admin_name||"Unknown");hdr.appendChild(nm);';
        echo '    var ts=document.createElement("span");ts.style.cssText="font-size:0.75rem;color:#999;";ts.textContent=(n.timestamp||"");hdr.appendChild(ts);';
        echo '    wrap.appendChild(hdr);';
        echo '    var body=document.createElement("div");body.style.cssText="font-size:0.88rem;color:#555;line-height:1.5;";body.textContent=(n.text||"");wrap.appendChild(body);';
        echo '    c.appendChild(wrap);';
        echo '  });';
        echo '  c.scrollTop=c.scrollHeight;';
        echo '}};';

        // Close modal on backdrop click
        echo 'document.getElementById("fps-notes-modal").addEventListener("click",function(e){if(e.target===this)fpsQueueNotes.close();});';

        echo '})();';
        echo '</script>';
    }

    /**
     * Render the filter bar with risk level, date range, search, and assignment filter.
     */
    private function fpsRenderFilterBar(
        string $modulelink,
        string $filterLevel,
        string $filterSearch,
        string $filterFrom,
        string $filterTo,
        string $filterAssigned,
        array $adminList,
        bool $hasAssignCol
    ): void {
        $actionUrl = htmlspecialchars($modulelink . '&tab=review_queue', ENT_QUOTES, 'UTF-8');

        echo '<div class="fps-filter-bar">';
        echo '<form method="GET" action="" class="fps-filter-form">';

        // Preserve modulelink params
        echo '<input type="hidden" name="module" value="fraud_prevention_suite">';
        echo '<input type="hidden" name="tab" value="review_queue">';

        // Source filter (auto vs all)
        $filterType = $_GET['check_type'] ?? 'auto_only';
        echo '<div class="fps-form-group">';
        echo '  <label><i class="fas fa-filter"></i> Source</label>';
        echo '  <select name="check_type" class="fps-select">';
        echo '    <option value="auto_only"' . ($filterType === 'auto_only' ? ' selected' : '') . '>New Signups & Orders</option>';
        echo '    <option value="all"' . ($filterType === 'all' ? ' selected' : '') . '>All Checks (incl. re-scans)</option>';
        echo '  </select>';
        echo '</div>';

        // Risk level
        echo '<div class="fps-form-group">';
        echo '  <label><i class="fas fa-shield-halved"></i> Risk Level</label>';
        echo '  <select name="risk_level" class="fps-select">';
        echo '    <option value="">All Levels</option>';
        echo '    <option value="critical"' . ($filterLevel === 'critical' ? ' selected' : '') . '>Critical</option>';
        echo '    <option value="high"' . ($filterLevel === 'high' ? ' selected' : '') . '>High</option>';
        echo '    <option value="medium"' . ($filterLevel === 'medium' ? ' selected' : '') . '>Medium</option>';
        echo '    <option value="low"' . ($filterLevel === 'low' ? ' selected' : '') . '>Low</option>';
        echo '  </select>';
        echo '</div>';

        // Assigned to filter
        if ($hasAssignCol && !empty($adminList)) {
            echo '<div class="fps-form-group">';
            echo '  <label><i class="fas fa-user-tag"></i> Assigned To</label>';
            echo '  <select name="assigned_to" class="fps-select">';
            echo '    <option value="">All</option>';
            echo '    <option value="mine"' . ($filterAssigned === 'mine' ? ' selected' : '') . '>My Checks</option>';
            echo '    <option value="unassigned"' . ($filterAssigned === 'unassigned' ? ' selected' : '') . '>Unassigned</option>';
            foreach ($adminList as $aId => $aName) {
                $sel = ((string)$aId === $filterAssigned) ? ' selected' : '';
                echo '    <option value="' . $aId . '"' . $sel . '>' . htmlspecialchars($aName, ENT_QUOTES, 'UTF-8') . '</option>';
            }
            echo '  </select>';
            echo '</div>';
        }

        // Date from
        echo '<div class="fps-form-group">';
        echo '  <label><i class="fas fa-calendar"></i> From</label>';
        echo '  <input type="date" name="date_from" class="fps-input" value="' . htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') . '">';
        echo '</div>';

        // Date to
        echo '<div class="fps-form-group">';
        echo '  <label><i class="fas fa-calendar-check"></i> To</label>';
        echo '  <input type="date" name="date_to" class="fps-input" value="' . htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') . '">';
        echo '</div>';

        // Search
        echo '<div class="fps-form-group">';
        echo '  <label><i class="fas fa-search"></i> Search</label>';
        echo '  <input type="text" name="search" class="fps-input" placeholder="Email, IP, or Order #" value="' . htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') . '">';
        echo '</div>';

        // Submit
        echo '<div class="fps-form-group" style="padding-top:24px;">';
        echo '  <button type="submit" class="fps-btn fps-btn-sm fps-btn-primary"><i class="fas fa-filter"></i> Filter</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    /**
     * Show clients and users that have NEVER been scanned.
     */
    private function fpsRenderUnscannedSection(string $modulelink): void
    {
        try {
            // Find clients with zero fraud checks
            $scannedIds = Capsule::table('mod_fps_checks')
                ->distinct()->pluck('client_id')->toArray();

            $unscannedClients = Capsule::table('tblclients')
                ->whereNotIn('id', $scannedIds ?: [0])
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'firstname', 'lastname', 'email', 'status', 'datecreated', 'ip']);

            // Count unscanned users (tblusers)
            $unscannedUserCount = 0;
            if (Capsule::schema()->hasTable('tblusers')) {
                $checkedEmails = Capsule::table('mod_fps_checks')
                    ->whereNotNull('email')
                    ->distinct()->pluck('email')->toArray();

                $userQuery = Capsule::table('tblusers')
                    ->whereNotIn('email', $checkedEmails ?: ['']);

                if (Capsule::schema()->hasTable('tblusers_clients')) {
                    $userQuery->whereExists(function ($sub) use ($scannedIds) {
                        $sub->selectRaw('1')
                            ->from('tblusers_clients')
                            ->join('tblclients', 'tblclients.id', '=', 'tblusers_clients.client_id')
                            ->whereRaw('tblusers_clients.auth_user_id = tblusers.id')
                            ->whereNotIn('tblusers_clients.client_id', $scannedIds ?: [0]);
                    });
                } elseif (Capsule::schema()->hasTable('tblclients_users')) {
                    $userQuery->whereExists(function ($sub) use ($scannedIds) {
                        $sub->selectRaw('1')
                            ->from('tblclients_users')
                            ->join('tblclients', 'tblclients.id', '=', 'tblclients_users.clients_id')
                            ->whereRaw('tblclients_users.users_id = tblusers.id')
                            ->whereNotIn('tblclients_users.clients_id', $scannedIds ?: [0]);
                    });
                }

                $unscannedUserCount = $userQuery->count();
            }

            $clientCount = count($unscannedClients);
            $totalUnscanned = Capsule::table('tblclients')
                ->whereNotIn('id', $scannedIds ?: [0])->count();

            if ($totalUnscanned === 0 && $unscannedUserCount === 0) {
                return; // All accounts have been scanned
            }

            $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');
            $scanLink = htmlspecialchars($modulelink . '&tab=mass_scan', ENT_QUOTES, 'UTF-8');

            echo '<div class="fps-card" style="margin-top:1.5rem;">';
            echo '<div class="fps-card-header" style="background:linear-gradient(135deg,#f5a623,#f7c948);">';
            echo '<h3 style="color:#1a1a2e;"><i class="fas fa-exclamation-triangle"></i> Unscanned Accounts</h3>';
            echo '<span class="fps-badge" style="background:rgba(0,0,0,0.15);color:#1a1a2e;">' . $totalUnscanned . ' clients + ' . $unscannedUserCount . ' users</span>';
            echo '</div>';
            echo '<div class="fps-card-body">';

            echo '<p style="margin:0 0 1rem;font-size:0.9rem;">These accounts have never been scanned by the fraud detection system. '
                . '<a href="' . $scanLink . '" style="color:#667eea;font-weight:600;">Run a Mass Scan</a> to check them all, or click individual scan buttons below.</p>';

            if ($clientCount > 0) {
                echo '<div style="overflow-x:auto;">';
                echo '<table class="fps-table"><thead><tr>';
                echo '<th>Client ID</th><th>Name</th><th>Email</th><th>Status</th><th>Registered</th><th>IP</th><th>Action</th>';
                echo '</tr></thead><tbody>';

                foreach ($unscannedClients as $c) {
                    $name = htmlspecialchars(trim($c->firstname . ' ' . $c->lastname), ENT_QUOTES, 'UTF-8');
                    $email = htmlspecialchars($c->email, ENT_QUOTES, 'UTF-8');
                    $statusClass = $c->status === 'Active' ? 'fps-badge-low' : ($c->status === 'Inactive' ? 'fps-badge-medium' : '');
                    $profileUrl = htmlspecialchars($modulelink . '&tab=client_profile&client_id=' . (int)$c->id, ENT_QUOTES, 'UTF-8');

                    echo '<tr>';
                    echo '<td>#' . (int)$c->id . '</td>';
                    echo '<td>' . $name . '</td>';
                    echo '<td style="font-size:0.85rem;">' . $email . '</td>';
                    echo '<td><span class="fps-badge ' . $statusClass . '">' . htmlspecialchars($c->status, ENT_QUOTES, 'UTF-8') . '</span></td>';
                    echo '<td style="font-size:0.85rem;">' . htmlspecialchars($c->datecreated ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '<td style="font-size:0.85rem;">' . htmlspecialchars($c->ip ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '<td><a href="' . $profileUrl . '" class="fps-btn fps-btn-xs fps-btn-primary"><i class="fas fa-search"></i> Scan</a></td>';
                    echo '</tr>';
                }

                echo '</tbody></table></div>';

                if ($totalUnscanned > $clientCount) {
                    echo '<p style="margin-top:0.5rem;font-size:0.85rem;opacity:0.7;">Showing ' . $clientCount . ' of ' . $totalUnscanned . ' unscanned clients. <a href="' . $scanLink . '">Run Mass Scan</a> to check all.</p>';
                }
            }

            if ($unscannedUserCount > 0) {
                echo '<div style="margin-top:1rem;padding:0.75rem;border-radius:8px;background:rgba(245,166,35,0.06);border:1px solid rgba(245,166,35,0.15);">';
                echo '<strong><i class="fas fa-user-slash"></i> ' . $unscannedUserCount . ' user accounts</strong> have never been scanned. ';
                echo 'Go to <a href="' . htmlspecialchars($modulelink . '&tab=bot_cleanup', ENT_QUOTES, 'UTF-8') . '" style="color:#667eea;font-weight:600;">Bot Cleanup</a> to scan and clean up user accounts.';
                echo '</div>';
            }

            echo '</div></div>';
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }
}
