<?php

// Load cPanel environment
require_once "/usr/local/cpanel/php/cpanel.php";

// Initialize cPanel API
$cpanel = new CPANEL();

// Fetch the user and home directory information
$username = getenv('REMOTE_USER');
if (!$username) {
    echo "<p>Error: Cannot determine username from environment</p>";
    exit;
}

// Determine the user's real home directory path
$user_info = posix_getpwnam($username);
$home_dir = $user_info['dir'];

if (!file_exists($home_dir)) {
    echo "<p>Error: Cannot determine home directory for user $username</p>";
    exit;
}

echo $cpanel->header('Inode Usage');

?>

<!-- Modernized UI -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="" crossorigin="anonymous">
<style>
/* compact modern styles */
.inode-card { margin-top: 18px; }
.header-row { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.controls { display:flex; gap:8px; align-items:center; }
.search-input { min-width:220px; max-width:420px; }
.spinner { width:44px; height:44px; border-radius:50%; border:4px solid rgba(0,0,0,0.08); border-top-color:#007bff; animation:spin .9s linear infinite; margin:auto; }
@keyframes spin { to { transform:rotate(360deg);} }
.table-fixed td, .table-fixed th { vertical-align: middle; }
.folder-name { color:#0d6efd; text-decoration:none; }
.small-muted { color:#6c757d; font-size:0.9rem; }
.row-fade { animation: fadeIn .18s ease; }
@keyframes fadeIn { from{opacity:0; transform:translateY(-4px)} to{opacity:1; transform:none} }
.toggle-btn { cursor:pointer; color:#495057; }
.card-summary { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:8px; }
.summary-item { padding:10px 14px; background:#f8f9fa; border-radius:8px; min-width:160px; box-shadow:0 0 0 1px rgba(0,0,0,0.03) inset; }
/* Make badge inline, smaller and vertically aligned so it doesn't overlay text */
.badge-unlimited {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap:6px;
  background: linear-gradient(90deg,#6f42c1,#e83e8c);
  color: #fff;
  padding: 4px 8px;
  border-radius: 999px;
  font-weight: 600;
  font-size: 0.85rem;
  line-height: 1;
  vertical-align: middle;
  white-space: nowrap;
}
@media (max-width:575px){ .controls { width:100%; justify-content:space-between; } .search-input{ min-width:120px; } }
</style>

<div class="inode-card card">
  <div class="card-body">
	<div class="header-row">
	  <div>
		<h4 style="margin:0">Inode Usage</h4>
		<div class="small-muted">Overview of inode consumption with expandable directories and direct File Manager links.</div>
	  </div>
	  <div class="controls">
		<input id="search" class="form-control form-control-sm search-input" placeholder="Search directories (type to filter)" />
		<button id="refreshBtn" class="btn btn-sm btn-outline-primary" title="Refresh"><i class="fa fa-sync-alt"></i></button>
	  </div>
	</div>

	<div class="card-summary" style="margin-top:12px;">
	  <div class="summary-item">
		<div class="small-muted">Total Inodes</div>
		<div id="totalInodes" style="font-weight:700; font-size:1.1rem">--</div>
	  </div>
	  <div class="summary-item">
		<div class="small-muted">Inode Limit</div>
		<div id="inodeLimit" style="font-weight:700; font-size:1.1rem">--</div>
	  </div>
	  <div class="summary-item small-muted" id="noteInfo">
		<i class="fa fa-info-circle"></i> Table updates in real-time on reload. Counting may take up to 1 minute for large accounts.
	  </div>
	</div>

	<!-- Preloader -->
	<div id="preloader" style="text-align:center; padding:26px;">
	  <div class="spinner" aria-hidden="true"></div>
	  <div style="margin-top:8px;" class="small-muted">Loading inode data…</div>
	</div>

	<!-- Table container -->
	<div id="inode_table_container" style="display:none;">
	  <div class="table-responsive">
		<table id="inodeTable" class="table table-hover table-fixed" style="min-width:600px;">
		  <thead class="table-light">
			<tr>
			  <th style="width:70%">Directory</th>
			  <th style="width:30%">Inodes</th>
			</tr>
		  </thead>
		  <tbody id="inode_tbody"></tbody>
		</table>
	  </div>
	</div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const preloader = document.getElementById('preloader');
    const dataContainer = document.getElementById('inode_table_container');
    const tbody = document.getElementById('inode_tbody');
    const totalInodesEl = document.getElementById('totalInodes');
    const inodeLimitEl = document.getElementById('inodeLimit');
    const searchInput = document.getElementById('search');
    const refreshBtn = document.getElementById('refreshBtn');

    const homeDir = "<?php echo addslashes($home_dir); ?>";
    const cp_security_token = "<?php echo $_ENV['cp_security_token']; ?>";
    const server_name = "<?php echo getenv('HTTP_HOST'); ?>";

    // helper functions
    function encodePathForClass(path) {
        return path.replace(/[^a-zA-Z0-9]/g, function (c) {
            return '_' + c.charCodeAt(0).toString(16);
        });
    }

    async function fetchJson(url) {
        const res = await fetch(url);
        if (!res.ok) throw new Error('Network response was not ok');
        return res.json();
    }

    async function fetchInodeData() {
        try {
            preloader.style.display = '';
            dataContainer.style.display = 'none';
            const data = await fetchJson('fetch_inode_data.live.php');

            preloader.style.display = 'none';
            dataContainer.style.display = 'block';

            // Populate summary
            totalInodesEl.textContent = data.total_inodes ?? '--';
            inodeLimitEl.innerHTML = (data.inode_limit === "Unlimited")
                ? '<span class="badge-unlimited">Unlimited</span>'
                : (data.inode_limit ?? '--');

            // Build rows
            tbody.innerHTML = '';
            if (data && data.directories && data.directories.length > 0) {
                // sort desc
                data.directories.sort((a,b)=> b.inodes - a.inodes);
                for (const dir of data.directories) {
                    const tr = document.createElement('tr');
                    tr.className = 'folder-row row-fade';
                    tr.dataset.path = dir.path;
                    tr.dataset.depth = 0;
                    const hasChildren = dir.has_subfolders;
                    const icon = hasChildren ? '<i class="fa fa-chevron-right toggle-btn" aria-hidden="true" style="margin-right:8px"></i>' : '<span style="display:inline-block;width:18px"></span>';
                    tr.innerHTML = `<td style="padding-left:16px;">${icon}<a href="#" class="folder-name" data-path="${dir.path}">${dir.path}</a></td><td>${dir.inodes}</td>`;
                    tbody.appendChild(tr);

                    // events
                    if (hasChildren) {
                        tr.querySelector('.toggle-btn').addEventListener('click', (e) => {
                            e.stopPropagation();
                            toggleSubfolders(tr, dir.path, 0);
                        });
                    }
                    tr.querySelector('.folder-name').addEventListener('click', (e)=>{
                        e.preventDefault();
                        openFileManager(dir.path);
                    });
                }

                // Add totals row -- use same badge HTML when unlimited
                const totals = document.createElement('tr');
                totals.className = 'table-light';
                const inodeLimitDisplay = (data.inode_limit === "Unlimited")
                    ? '<span class="badge-unlimited">Unlimited</span>'
                    : (data.inode_limit ?? '--');
                totals.innerHTML = `<td><strong>Total</strong></td><td><strong>${data.total_inodes} / ${inodeLimitDisplay}</strong></td>`;
                tbody.appendChild(totals);
            } else {
                tbody.innerHTML = '<tr><td colspan="2" class="small-muted">No data available.</td></tr>';
            }
        } catch (err) {
            preloader.innerHTML = '<div class="small-muted">Failed to load data. Try refresh.</div>';
            console.error('Error fetching inode data:', err);
        }
    }

    function collapseSubfolders(encodedPath) {
        document.querySelectorAll('.subfolder-of-' + encodedPath).forEach(subRow => {
            const subPath = subRow.dataset.path;
            const subEncodedPath = encodePathForClass(subPath);
            subRow.remove();
            collapseSubfolders(subEncodedPath); // recursive
        });
    }

    async function fetchSubfolders(path) {
        return fetchJson('fetch_subfolders.live.php?subfolder=' + encodeURIComponent(path));
    }

    async function toggleSubfolders(row, path, depth) {
        const encodedPath = encodePathForClass(path);
        const icon = row.querySelector('.toggle-btn');
        const expanded = row.classList.contains('expanded');

        if (expanded) {
            row.classList.remove('expanded');
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-right');
            collapseSubfolders(encodedPath);
            return;
        }

        row.classList.add('expanded');
        icon.classList.remove('fa-chevron-right');
        icon.classList.add('fa-chevron-down');

        try {
            const subfolders = await fetchSubfolders(path);
            // sort by inodes desc
            subfolders.sort((a,b)=> b.inodes - a.inodes);

            // insert subrows just after the parent
            let insertAfter = row;
            for (const sub of subfolders) {
                const subRow = document.createElement('tr');
                subRow.className = `folder-row subfolder-of-${encodedPath} row-fade`;
                subRow.dataset.path = sub.path;
                subRow.dataset.depth = depth + 1;
                const padding = 18 + (parseInt(subRow.dataset.depth) * 18);
                const iconHtml = sub.has_subfolders ? '<i class="fa fa-chevron-right toggle-btn" style="margin-right:8px"></i>' : '<span style="display:inline-block;width:18px"></span>';
                subRow.innerHTML = `<td style="padding-left:${padding}px">${iconHtml}<a href="#" class="folder-name" data-path="${sub.path}">${sub.path.replace(homeDir,'') || sub.path}</a></td><td>${sub.inodes}</td>`;

                insertAfter.parentNode.insertBefore(subRow, insertAfter.nextSibling);
                insertAfter = subRow;

                // attach events
                if (sub.has_subfolders) {
                    const tbtn = subRow.querySelector('.toggle-btn');
                    tbtn.addEventListener('click', (e)=>{ e.stopPropagation(); toggleSubfolders(subRow, sub.path, depth+1); });
                }
                subRow.querySelector('.folder-name').addEventListener('click', (e)=>{
                    e.preventDefault();
                    openFileManager(sub.path);
                });
            }
        } catch (err) {
            console.error('Error fetching subfolders:', err);
            alert('Unable to load subfolders: ' + (err.message || err));
        }
    }

    function openFileManager(fullPath) {
        const folderPath = fullPath.replace(homeDir, '');
        const fileManagerUrl = `https://${server_name}:2083${cp_security_token}/frontend/jupiter/filemanager/index.html?dirselect=homedir&dir=${encodeURIComponent(folderPath)}`;
        window.open(fileManagerUrl, '_blank');
    }

    // search/filter with debounce
    function debounce(fn, wait=250){
        let t;
        return function(...args){ clearTimeout(t); t = setTimeout(()=>fn.apply(this,args), wait); };
    }
    function filterTable(q){
        const rows = Array.from(tbody.querySelectorAll('tr.folder-row'));
        const needle = q.trim().toLowerCase();
        rows.forEach(r=>{
            const name = (r.dataset.path || '').toLowerCase();
            r.style.display = needle === '' ? '' : (name.indexOf(needle) === -1 ? 'none' : '');
        });
    }

    searchInput.addEventListener('input', debounce((e)=> filterTable(e.target.value), 120));
    refreshBtn.addEventListener('click', ()=> fetchInodeData());

    // initial load
    fetchInodeData();
});
</script>

<?php
echo $cpanel->footer();
$cpanel->end();
?>
