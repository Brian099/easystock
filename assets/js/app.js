/**
 * Stock Management System - Native Single Page App Controller
 */

// Global App State
const state = {
    user: null,
    currentView: 'dashboard',
    settings: {
        allowEditStock: 'false',
        unit: '个',
        brand: ''
    },
    suggestions: {
        brands: [],
        units: [],
        locals: []
    },
    productsPagination: { page: 1, total_pages: 1 },
    logsPagination: { page: 1, total_pages: 1 }
};

// DOM Elements & Event Listeners Cache
document.addEventListener('DOMContentLoaded', () => {
    initApp();
});

function initApp() {
    checkAuthStatus();
    setupRouting();
    setupNavigation();
    setupForms();
    setupModals();
    setupSearchFilters();
}

/* --------------------------------------------------
 * 1. Authentication & Routing
 * -------------------------------------------------- */
function checkAuthStatus() {
    fetch('api/auth.php?action=status')
        .then(res => {
            if (res.status === 503) {
                showInstallScreen();
                throw new Error('Not installed');
            }
            return res.json();
        })
        .then(data => {
            if (data.logged_in) {
                state.user = data.user;
                showMainScreen();
            } else {
                showLoginScreen();
            }
        })
        .catch(err => {
            if (err.message !== 'Not installed') {
                showLoginScreen();
            }
        });
}

function showInstallScreen() {
    document.getElementById('main-screen').classList.remove('active');
    document.getElementById('login-screen').classList.remove('active');
    document.getElementById('install-screen').classList.add('active');
    
    // Fetch env/prefill configurations
    fetch('api/install.php')
        .then(res => res.json())
        .then(data => {
            if (data.prefill) {
                document.getElementById('install-db-host').value = data.prefill.db_host;
                document.getElementById('install-db-name').value = data.prefill.db_name;
                document.getElementById('install-db-user').value = data.prefill.db_user;
                document.getElementById('install-db-pass').value = data.prefill.db_pass;
                document.getElementById('install-admin-user').value = data.prefill.admin_user;
            }
        });
}

function showLoginScreen() {
    document.getElementById('main-screen').classList.remove('active');
    document.getElementById('install-screen').classList.remove('active');
    document.getElementById('login-screen').classList.add('active');
    state.user = null;
}

function showMainScreen() {
    document.getElementById('login-screen').classList.remove('active');
    document.getElementById('install-screen').classList.remove('active');
    document.getElementById('main-screen').classList.add('active');
    
    // Set username in side drawer
    document.getElementById('drawer-username').textContent = state.user.username;
    
    // Set user role badge
    const roleBadge = document.getElementById('header-user-role');
    const usersCard = document.getElementById('settings-users-card');
    if (state.user.role === 'admin') {
        roleBadge.textContent = '管理员';
        roleBadge.className = 'badge';
        if (usersCard) usersCard.classList.remove('hidden');
    } else {
        roleBadge.textContent = '操作员';
        roleBadge.className = 'badge badge-secondary';
        if (usersCard) usersCard.classList.add('hidden');
    }
    
    // Load config and suggestions first
    loadSettings();
    
    // Navigate to current hash or dashboard
    const initialView = window.location.hash.substring(1) || 'dashboard';
    navigateTo(initialView);
}

function setupRouting() {
    window.addEventListener('hashchange', () => {
        if (!state.user) return;
        const targetView = window.location.hash.substring(1) || 'dashboard';
        navigateTo(targetView);
    });
}

function navigateTo(viewId) {
    const validViews = ['dashboard', 'products', 'logs', 'settings'];
    if (!validViews.includes(viewId)) viewId = 'dashboard';
    
    state.currentView = viewId;
    
    // Toggle active classes on view containers
    document.querySelectorAll('.view').forEach(view => {
        view.classList.remove('active');
    });
    const activeView = document.getElementById(`view-${viewId}`);
    if (activeView) activeView.classList.add('active');
    
    // Toggle active states on menus and docks
    document.querySelectorAll('.nav-item, .dock-item').forEach(item => {
        if (item.getAttribute('data-target') === viewId) {
            item.classList.add('active');
        } else {
            item.classList.remove('active');
        }
    });
    
    // Close mobile side drawer if open
    closeDrawer();
    
    // Trigger data loading for the specific view
    loadViewData(viewId);
}

function loadViewData(viewId) {
    if (viewId === 'dashboard') {
        loadDashboardMetrics();
    } else if (viewId === 'products') {
        state.productsPagination.page = 1;
        loadProductsList();
    } else if (viewId === 'logs') {
        state.logsPagination.page = 1;
        loadLogsList();
    } else if (viewId === 'settings') {
        if (state.user && state.user.role === 'admin') {
            loadUsersList();
            loadBarcodeStats();
        }
    }
}

/* --------------------------------------------------
 * 2. Side Drawer & Navigation interactions
 * -------------------------------------------------- */
function setupNavigation() {
    const menuToggle = document.getElementById('menu-toggle');
    const drawerClose = document.getElementById('drawer-close');
    const drawerOverlay = document.getElementById('drawer-overlay');
    const drawer = document.getElementById('app-drawer');
    
    const openDrawerFunc = () => {
        drawer.classList.add('open');
        drawerOverlay.classList.add('active');
    };
    
    menuToggle.addEventListener('click', openDrawerFunc);
    drawerClose.addEventListener('click', closeDrawer);
    drawerOverlay.addEventListener('click', closeDrawer);
    
    // Handle logout
    document.getElementById('logout-btn').addEventListener('click', () => {
        if (confirm('确认退出系统吗？')) {
            fetch('api/auth.php?action=logout', { method: 'POST' })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast('成功退出登录');
                        showLoginScreen();
                    }
                });
        }
    });
}

function closeDrawer() {
    const drawer = document.getElementById('app-drawer');
    const drawerOverlay = document.getElementById('drawer-overlay');
    if (drawer && drawerOverlay) {
        drawer.classList.remove('open');
        drawerOverlay.classList.remove('active');
    }
}

/* --------------------------------------------------
 * 3. Settings & Autocompletion List Suggestions
 * -------------------------------------------------- */
function loadSettings() {
    fetch('api/settings.php')
        .then(res => res.json())
        .then(data => {
            state.settings = data.settings;
            state.suggestions = data.suggestions;
            
            // Populate combobox options and filters
            populateSuggestions();
            
            // Apply setting inputs on Settings view
            const allowEdit = document.getElementById('setting-allow-edit');
            if (allowEdit) allowEdit.checked = (state.settings.allowEditStock === 'true');
            
            // Disable controls and hide save button if user is not admin
            const isAdmin = (state.user && state.user.role === 'admin');
            if (allowEdit) allowEdit.disabled = !isAdmin;
            
            const saveBtn = document.querySelector('#global-settings-form button[type="submit"]');
            if (saveBtn) {
                if (isAdmin) {
                    saveBtn.classList.remove('hidden');
                } else {
                    saveBtn.classList.add('hidden');
                }
            }
        });
}

function populateSuggestions() {
    renderComboboxDropdown('suggested-units-menu', 'prod-unit', state.suggestions.units || []);
    renderComboboxDropdown('suggested-brands-menu', 'prod-brand', state.suggestions.brands || []);
    renderComboboxDropdown('suggested-locals-menu', 'prod-local', state.suggestions.locals || []);

    // Populate brand filter options
    const brandFilter = document.getElementById('product-filter-brand');
    if (brandFilter) {
        const currentBrandVal = brandFilter.value;
        brandFilter.innerHTML = '<option value="">全部品牌</option>';
        (state.suggestions.brands || []).forEach(b => {
            const opt = document.createElement('option');
            opt.value = b;
            opt.textContent = b;
            brandFilter.appendChild(opt);
        });
        brandFilter.value = currentBrandVal;
    }
    
    // Populate local filter options
    const localFilter = document.getElementById('product-filter-local');
    if (localFilter) {
        const currentLocalVal = localFilter.value;
        localFilter.innerHTML = '<option value="">全部仓位</option>';
        (state.suggestions.locals || []).forEach(l => {
            const opt = document.createElement('option');
            opt.value = l;
            opt.textContent = l;
            localFilter.appendChild(opt);
        });
        localFilter.value = currentLocalVal;
    }
}

function renderComboboxDropdown(menuId, inputId, items) {
    const menu = document.getElementById(menuId);
    const input = document.getElementById(inputId);
    if (!menu || !input) return;

    const render = (filterVal = '', isTyping = false) => {
        menu.innerHTML = '';
        const search = (filterVal || '').trim().toLowerCase();
        // If not typing (e.g. click/focus/toggle), show ALL items; if typing, filter items
        const filtered = (!isTyping || search === '') 
            ? items 
            : items.filter(item => item.toLowerCase().includes(search));

        if (filtered.length === 0) {
            menu.innerHTML = '<div class="combobox-empty">无匹配预设 (可直接输入自定义)</div>';
            return;
        }

        filtered.forEach(item => {
            const div = document.createElement('div');
            div.className = `combobox-item ${input.value === item ? 'active' : ''}`;
            div.textContent = item;
            div.addEventListener('mousedown', (e) => {
                e.preventDefault();
                input.value = item;
                menu.classList.remove('show');
            });
            menu.appendChild(div);
        });
    };

    input._renderCombobox = render;
    render('', false);

    // Re-render and show on input typing (filtered)
    input.oninput = () => {
        render(input.value, true);
        menu.classList.add('show');
    };

    // Show ALL items on focus/click so user can select any preset freely
    input.onfocus = () => {
        render('', false);
        menu.classList.add('show');
    };

    input.onclick = () => {
        render('', false);
        menu.classList.add('show');
    };

    // Hide on blur
    input.onblur = () => {
        setTimeout(() => {
            menu.classList.remove('show');
        }, 180);
    };
}

/* --------------------------------------------------
 * 4. Forms Submission Handlers
 * -------------------------------------------------- */
function setupForms() {
    // 0. Install Form
    const installForm = document.getElementById('install-form');
    if (installForm) {
        installForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('install-submit-btn');
            const errorDiv = document.getElementById('install-error');
            const loadingDiv = document.getElementById('install-loading');
            
            errorDiv.classList.add('hidden');
            loadingDiv.classList.remove('hidden');
            submitBtn.disabled = true;
            
            const payload = {
                db_host: document.getElementById('install-db-host').value,
                db_name: document.getElementById('install-db-name').value,
                db_user: document.getElementById('install-db-user').value,
                db_pass: document.getElementById('install-db-pass').value,
                admin_user: document.getElementById('install-admin-user').value,
                admin_pass: document.getElementById('install-admin-pass').value,
                import_demo: document.getElementById('install-import-demo')?.checked || false
            };
            
            fetch('api/install.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => {
                loadingDiv.classList.add('hidden');
                submitBtn.disabled = false;
                if (!res.ok) {
                    return res.json().then(err => { throw new Error(err.error || '安装失败'); });
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    showToast('系统安装并初始化成功！');
                    checkAuthStatus();
                }
            })
            .catch(err => {
                loadingDiv.classList.add('hidden');
                submitBtn.disabled = false;
                errorDiv.textContent = err.message;
                errorDiv.classList.remove('hidden');
            });
        });
    }

    // A. Login Form
    const loginForm = document.getElementById('login-form');
    loginForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const username = document.getElementById('login-username').value;
        const password = document.getElementById('login-password').value;
        const loginError = document.getElementById('login-error');
        
        loginError.classList.add('hidden');
        
        fetch('api/auth.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password })
        })
        .then(res => {
            if (!res.ok) {
                return res.json().then(err => { throw new Error(err.error || '登录失败'); });
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                state.user = data.user;
                showToast('登录成功');
                showMainScreen();
                // Clear form
                loginForm.reset();
            }
        })
        .catch(err => {
            loginError.textContent = err.message;
            loginError.classList.remove('hidden');
        });
    });
    
    // B. Product Form
    const productForm = document.getElementById('product-form');
    productForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const prodId = document.getElementById('prod-id').value;
        
        const payload = {
            name: document.getElementById('prod-name').value,
            model: document.getElementById('prod-model').value,
            spec: document.getElementById('prod-spec').value,
            barcode: document.getElementById('prod-barcode').value,
            unit: document.getElementById('prod-unit').value,
            brand: document.getElementById('prod-brand').value,
            local: document.getElementById('prod-local').value,
            price: document.getElementById('prod-price').value,
            mark: document.getElementById('prod-mark').value
        };
        
        if (!prodId) {
            // Include initial stock only on creation
            payload.stock = document.getElementById('prod-stock').value;
        } else {
            // Update mode includes stock only if allowEditStock settings is true
            if (state.settings.allowEditStock === 'true') {
                payload.stock = document.getElementById('prod-stock').value;
            }
        }
        
        const url = prodId ? `api/products.php?id=${prodId}` : 'api/products.php';
        const method = prodId ? 'PUT' : 'POST';
        
        fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => {
            if (!res.ok) {
                return res.json().then(err => { throw new Error(err.error); });
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                showToast(prodId ? '修改保存成功' : '新增商品成功');
                closeModal('product-modal');
                loadViewData(state.currentView);
                loadSettings(); // refresh autocompletion list suggestions
            }
        })
        .catch(err => showToast(err.message));
    });
    
    // C. Quick Transaction Form (Manual in/out/re log)
    const quickTxnForm = document.getElementById('quick-txn-form');
    quickTxnForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const prodId = document.getElementById('txn-selected-product-id').value;
        if (!prodId) {
            showToast('请先选择要操作的出入库商品！');
            return;
        }
        
        const payload = {
            product_id: prodId,
            type: document.querySelector('input[name="txn-type"]:checked').value,
            quantity: document.getElementById('txn-quantity').value,
            mark: document.getElementById('txn-mark').value
        };
        
        fetch('api/stock.php?action=log', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => {
            if (!res.ok) {
                return res.json().then(err => { throw new Error(err.error); });
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                showToast('库存操作记录成功');
                closeModal('quick-transaction-modal');
                loadViewData(state.currentView);
            }
        })
        .catch(err => showToast(err.message));
    });
    
    // D. Global Rules Settings
    const settingsForm = document.getElementById('global-settings-form');
    if (settingsForm) {
        settingsForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const allowEdit = document.getElementById('setting-allow-edit').checked ? 'true' : 'false';
            
            fetch('api/settings.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    allowEditStock: allowEdit
                })
            })
            .then(res => {
                if (!res.ok) {
                    return res.json().then(err => { throw new Error(err.error); });
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    state.settings = data.settings;
                    showToast('系统设置已保存');
                    loadSettings();
                }
            })
            .catch((err) => showToast(err.message || '无法保存设置'));
        });
    }
    
    // E. Change Password
    const changePassForm = document.getElementById('change-password-form');
    changePassForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const old_password = document.getElementById('old-pass').value;
        const new_password = document.getElementById('new-pass').value;
        const passSuccess = document.getElementById('pass-success');
        const passError = document.getElementById('pass-error');
        
        passSuccess.classList.add('hidden');
        passError.classList.add('hidden');
        
        fetch('api/auth.php?action=change_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ old_password, new_password })
        })
        .then(res => {
            if (!res.ok) {
                return res.json().then(err => { throw new Error(err.error); });
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                passSuccess.textContent = '密码修改成功！';
                passSuccess.classList.remove('hidden');
                changePassForm.reset();
            }
        })
        .catch(err => {
            passError.textContent = err.message;
            passError.classList.remove('hidden');
        });
    });

    // F. User Form (Create/Edit)
    const userForm = document.getElementById('user-form');
    if (userForm) {
        userForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const userId = document.getElementById('user-form-id').value;
            const username = document.getElementById('user-form-username').value.trim();
            const password = document.getElementById('user-form-password').value;
            const role = document.getElementById('user-form-role').value;

            if (!username) {
                showToast('用户名不能为空');
                return;
            }

            // Create mode must have password
            if (!userId && !password) {
                showToast('新用户必须设置密码');
                return;
            }

            if (password && password.length < 6) {
                showToast('密码长度至少为 6 位');
                return;
            }

            const payload = { username, role };
            if (password) {
                payload.password = password;
            }

            const url = userId ? `api/users.php?id=${userId}` : 'api/users.php';
            const method = userId ? 'PUT' : 'POST';

            fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => {
                if (!res.ok) {
                    return res.json().then(err => { throw new Error(err.error || '保存失败'); });
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    showToast(userId ? '修改账户成功' : '新增账号成功');
                    closeModal('user-modal');
                    loadUsersList();
                }
            })
            .catch(err => showToast(err.message));
        });
    }
}

/* --------------------------------------------------
 * 5. Dashboard Data Loading
 * -------------------------------------------------- */
function loadDashboardMetrics() {
    fetch('api/stock.php?action=stats')
        .then(res => res.json())
        .then(data => {
            document.getElementById('metric-total-items').textContent = data.total_items;
            document.getElementById('metric-total-qty').textContent = data.total_stock_qty;
            document.getElementById('metric-low-stock').textContent = data.low_stock_count;
        });
        
    // Load recent activities
    fetch('api/stock.php?limit=5')
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('dashboard-recent-logs');
            container.innerHTML = '';
            
            if (data.logs.length === 0) {
                container.innerHTML = '<div class="loading-spinner">暂无库存变更历史</div>';
                return;
            }
            
            data.logs.forEach(log => {
                const item = document.createElement('div');
                item.className = 'activity-item';
                
                let iconClass = 'fa-circle-arrow-down';
                let badgeClass = 'badge-in';
                let signedStr = `+${log.quantity}`;
                let qtyClass = 'qty-plus';
                
                if (log.type === 'out') {
                    iconClass = 'fa-circle-arrow-up';
                    badgeClass = 'badge-out';
                    signedStr = `${log.quantity}`;
                    qtyClass = 'qty-minus';
                } else if (log.type === 're') {
                    iconClass = 'fa-rotate-left';
                    badgeClass = 'badge-re';
                    signedStr = `+${log.quantity}`;
                    qtyClass = 'qty-plus';
                } else if (log.type === 'del') {
                    iconClass = 'fa-trash-can';
                    badgeClass = 'badge-del';
                    signedStr = `${log.quantity}`;
                    qtyClass = 'qty-minus';
                }
                
                // Formulate description
                let detailsStr = log.history_name;
                if (log.history_model) {
                    detailsStr += ` (${log.history_model})`;
                }
                
                item.innerHTML = `
                    <div class="activity-main">
                        <div class="act-type-badge ${badgeClass}"><i class="fa-solid ${iconClass}"></i></div>
                        <div class="activity-info">
                            <h4>${detailsStr}</h4>
                            <p>${log.created_at} • 操作人: ${log.operator_name || '系统'}</p>
                        </div>
                    </div>
                    <div class="activity-qty ${qtyClass}">${signedStr}</div>
                `;
                container.appendChild(item);
            });
        });
}

/* --------------------------------------------------
 * 6. Products Management (CRUD, pagination, searches)
 * -------------------------------------------------- */
function loadProductsList() {
    const search = document.getElementById('product-search-input').value;
    const brand = document.getElementById('product-filter-brand').value;
    const local = document.getElementById('product-filter-local').value;
    const page = state.productsPagination.page;
    
    let url = `api/products.php?page=${page}&limit=15&search=${encodeURIComponent(search)}&brand=${encodeURIComponent(brand)}&local=${encodeURIComponent(local)}`;
    
    // Check if we reached this via low-stock warning card
    if (state.filterLowStockOnly) {
        url += '&low_stock=1';
    }
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            state.productsPagination = data.pagination;
            state.currentProducts = {};
            const container = document.getElementById('products-list-container');
            container.innerHTML = '';
            
            if (data.products.length === 0) {
                container.innerHTML = '<div class="loading-spinner">没有找到符合要求的商品</div>';
                document.getElementById('products-pagination').innerHTML = '';
                return;
            }
            
            // 1. Mobile Card List Layout
            const cardWrapper = document.createElement('div');
            cardWrapper.className = 'products-card-list mobile-only';
            
            // 2. Desktop/Tablet Table Layout
            const tableWrapper = document.createElement('div');
            tableWrapper.className = 'table-responsive glass desktop-only';
            
            const table = document.createElement('table');
            table.className = 'product-table';
            
            table.innerHTML = `
                <thead>
                    <tr>
                        <th style="width: 36px; text-align: center;"></th>
                        <th style="width: 50px; text-align: center;">图片</th>
                        <th>商品名称 / 型号 / 规格</th>
                        <th>品牌</th>
                        <th>仓位</th>
                        <th style="text-align: right; width: 95px;">价格</th>
                        <th style="text-align: right; width: 105px;">库存</th>
                        <th>备注</th>
                        <th style="text-align: center; width: 195px;">操作</th>
                    </tr>
                </thead>
                <tbody></tbody>
            `;
            
            const tbody = table.querySelector('tbody');
            
            data.products.forEach(p => {
                state.currentProducts[p.id] = p;

                // Set stock warnings color
                let stockClass = 'status-ok';
                if (parseInt(p.stock) === 0) {
                    stockClass = 'status-danger';
                } else if (parseInt(p.stock) <= 2) {
                    stockClass = 'status-warn';
                }
                
                // Model and Spec strings
                let metaSub = '';
                if (p.model || p.spec) {
                    metaSub = [p.model, p.spec].filter(Boolean).join(' / ');
                }
                
                // --- RENDER TABLE ROW (DESKTOP) ---
                const tr = document.createElement('tr');
                tr.className = 'product-table-row product-row-clickable';
                tr.setAttribute('data-id', p.id);
                tr.setAttribute('title', '点击展开 / 收起商品详情');
                
                // Image or icon fallback
                let tableImgHtml = '<i class="fa-solid fa-cube" style="font-size: 20px; color: var(--text-light);"></i>';
                if (p.image) {
                    tableImgHtml = `<img src="${p.image}" alt="${escapeHtml(p.name)}" class="table-thumb" style="width: 36px; height: 36px; object-fit: cover; border-radius: var(--border-radius-sm); cursor: pointer;">`;
                }
                
                let tableDeleteActionHtml = '';
                if (state.user.role === 'admin') {
                    tableDeleteActionHtml = `<button class="btn btn-sm btn-secondary delete-item-btn" data-id="${p.id}" style="padding: 4px 8px; font-size: 11px; color: var(--danger-color); background: var(--danger-light);"><i class="fa-solid fa-trash-can"></i> 删除</button>`;
                }
                
                tr.innerHTML = `
                    <td style="text-align: center; vertical-align: middle; padding: 0 4px;">
                        <span class="expand-toggle-icon"><i class="fa-solid fa-chevron-right"></i></span>
                    </td>
                    <td style="text-align: center; vertical-align: middle;">
                        <div class="product-thumbnail-cell">${tableImgHtml}</div>
                    </td>
                    <td style="vertical-align: middle;">
                        <div class="product-info-cell">
                            <span class="product-info-name" style="font-weight: 600; display: block; font-size: 14px;">${escapeHtml(p.name)}</span>
                            ${metaSub ? `<span class="product-info-spec" style="font-size: 11px; color: var(--text-light); display: block; margin-top: 2px;">${escapeHtml(metaSub)}</span>` : ''}
                        </div>
                    </td>
                    <td style="vertical-align: middle;">
                        ${p.brand ? `<span class="tag-badge" style="background-color: var(--border-color); padding: 2px 6px; border-radius: 4px; font-size: 11px;">${escapeHtml(p.brand)}</span>` : '--'}
                    </td>
                    <td style="vertical-align: middle;">
                        ${p.local ? `<span class="tag-badge" style="background-color: var(--border-color); padding: 2px 6px; border-radius: 4px; font-size: 11px;">${escapeHtml(p.local)}</span>` : '--'}
                    </td>
                    <td style="text-align: right; vertical-align: middle; font-weight: 700; color: var(--primary-color);">
                        ¥${p.price}
                    </td>
                    <td style="text-align: right; vertical-align: middle; font-weight: 700;">
                        <span class="${stockClass}" style="font-size: 15px;">${p.stock}</span>
                        <span style="font-size: 11px; font-weight: normal; color: var(--text-secondary); margin-left: 2px;">${escapeHtml(p.unit || '个')}</span>
                    </td>
                    <td style="vertical-align: middle;">
                        ${p.mark ? `<span style="font-size: 12px; color: var(--text-light);">${escapeHtml(p.mark)}</span>` : '--'}
                    </td>
                    <td style="text-align: center; vertical-align: middle;">
                        <div class="table-actions" style="display: flex; justify-content: center; gap: 6px;">
                            <button class="btn btn-sm btn-primary adjust-stock-btn" data-id="${p.id}" style="padding: 4px 8px; font-size: 11px;"><i class="fa-solid fa-right-left"></i> 出入</button>
                            <button class="btn btn-sm btn-secondary edit-item-btn" data-id="${p.id}" style="padding: 4px 8px; font-size: 11px;"><i class="fa-solid fa-pen-to-square"></i> 编辑</button>
                            ${tableDeleteActionHtml}
                        </div>
                    </td>
                `;

                // Accordion Expand Sub-Row (Desktop)
                const expandTr = document.createElement('tr');
                expandTr.className = 'product-expand-tr';
                expandTr.setAttribute('data-expand-for', p.id);
                expandTr.style.display = 'none';
                expandTr.innerHTML = `
                    <td colspan="9" class="product-expand-td">
                        <div class="product-expand-container" id="expand-content-desktop-${p.id}"></div>
                    </td>
                `;

                tbody.appendChild(tr);
                tbody.appendChild(expandTr);
                
                // --- RENDER COMPACT CARD (MOBILE) ---
                const card = document.createElement('div');
                card.className = 'compact-product-card glass';
                card.setAttribute('data-id', p.id);
                
                let cardImgHtml = '<i class="fa-solid fa-cube" style="font-size: 18px; color: var(--text-light);"></i>';
                if (p.image) {
                    cardImgHtml = `<img src="${p.image}" alt="${escapeHtml(p.name)}" class="table-thumb" style="width: 44px; height: 44px; object-fit: cover; border-radius: 6px; cursor: pointer;">`;
                }
                
                let cardDeleteActionHtml = '';
                if (state.user.role === 'admin') {
                    cardDeleteActionHtml = `<button class="btn-icon delete-item-btn" data-id="${p.id}" style="background: var(--danger-light); color: var(--danger-color);"><i class="fa-solid fa-trash-can"></i></button>`;
                }
                
                card.innerHTML = `
                    <div class="compact-product-card-header" data-id="${p.id}">
                        <div class="card-thumb-area" style="width: 44px; height: 44px; display: flex; justify-content: center; align-items: center; background: rgba(0,0,0,0.02); border-radius: 6px; overflow: hidden; flex-shrink: 0;">
                            ${cardImgHtml}
                        </div>
                        <div class="card-details-area" style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 4px;">
                                <h4 style="font-size: 13.5px; font-weight: 600; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-primary); flex: 1;">${escapeHtml(p.name)}</h4>
                                <span class="expand-toggle-icon" style="flex-shrink: 0;"><i class="fa-solid fa-chevron-right" style="font-size: 11px;"></i></span>
                            </div>
                            <div style="font-size: 11px; color: var(--text-light); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                ${metaSub ? `<span>${escapeHtml(metaSub)}</span>` : '无规格'}
                                ${p.brand ? ` | <span>${escapeHtml(p.brand)}</span>` : ''}
                                ${p.local ? ` | <span style="color: var(--primary-color); font-weight: 500;">${escapeHtml(p.local)}</span>` : ''}
                            </div>
                            <div style="font-size: 11px; display: flex; align-items: center; gap: 8px; margin-top: 1px;">
                                <span style="font-weight: 700; color: var(--primary-color);">¥${p.price}</span>
                                <span class="${stockClass}" style="font-weight: 600;">库存: ${p.stock} ${escapeHtml(p.unit || '个')}</span>
                            </div>
                            ${p.mark ? `<div style="font-size: 11px; color: var(--text-light); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><i class="fa-solid fa-sticky-note" style="margin-right: 3px; font-size: 10px;"></i>${escapeHtml(p.mark)}</div>` : ''}
                        </div>
                        <div class="card-actions-area" style="display: flex; gap: 6px; align-items: center; flex-shrink: 0;">
                            <button class="btn-icon adjust-stock-btn" data-id="${p.id}" style="background: var(--primary-light); color: var(--primary-color);" title="快捷出入库"><i class="fa-solid fa-right-left"></i></button>
                            <button class="btn-icon edit-item-btn" data-id="${p.id}" style="background: var(--border-color); color: var(--text-secondary);" title="编辑商品"><i class="fa-solid fa-pen-to-square"></i></button>
                            ${cardDeleteActionHtml}
                        </div>
                    </div>
                    <div class="compact-product-card-body" id="expand-content-mobile-${p.id}" style="display: none;"></div>
                `;
                cardWrapper.appendChild(card);
            });
            
            tableWrapper.appendChild(table);
            container.appendChild(cardWrapper);
            container.appendChild(tableWrapper);
            
            // Render pagination controls
            renderPagination('products-pagination', state.productsPagination, (targetPage) => {
                state.productsPagination.page = targetPage;
                loadProductsList();
            });
            
            // Attach card & accordion events
            attachProductCardEvents();
        });
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function buildProductDetailPanelHtml(p, prefix = 'desktop') {
    const metaSub = [p.model, p.spec].filter(Boolean).join(' / ') || '--';
    const barcodeHtml = p.barcode 
        ? `<span>${escapeHtml(p.barcode)}</span> <button type="button" class="btn-copy-mini copy-barcode-btn" data-barcode="${escapeHtml(p.barcode)}" title="复制条码"><i class="fa-regular fa-copy"></i> 复制</button>`
        : '<span style="color: var(--text-light); font-weight: normal;">无条码</span>';

    return `
        <div class="product-expand-panel">
            <div class="expand-layout-grid">
                <!-- Left: Product Images Gallery (Full Height) -->
                <div class="detail-block detail-block-left-images">
                    <div class="detail-images-list" id="detail-images-${prefix}-${p.id}">
                        <div class="loading-spinner" style="padding: 6px; font-size: 11px;"><i class="fa-solid fa-spinner fa-spin"></i> 加载图片...</div>
                    </div>
                </div>

                <!-- Right Column: Top (Attributes) + Bottom (Recent Transactions) -->
                <div class="expand-layout-right">
                    <!-- Top Right: Attributes Grid -->
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-item-label">条形码 / 编码</span>
                            <div class="detail-item-val">${barcodeHtml}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item-label">型号 / 规格</span>
                            <div class="detail-item-val">${escapeHtml(metaSub)}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item-label">品牌 / 厂商</span>
                            <div class="detail-item-val">${escapeHtml(p.brand || '--')}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item-label">存放仓位</span>
                            <div class="detail-item-val"><span style="color: var(--primary-color);">${escapeHtml(p.local || '--')}</span></div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item-label">单价 / 单位</span>
                            <div class="detail-item-val"><span style="color: var(--primary-color);">¥${p.price}</span> / ${escapeHtml(p.unit || '个')}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item-label">当前库存</span>
                            <div class="detail-item-val"><span style="font-size: 15px; font-weight: 700;">${p.stock}</span> ${escapeHtml(p.unit || '个')}</div>
                        </div>
                        <div class="detail-item" style="grid-column: 1 / -1;">
                            <span class="detail-item-label">备注信息</span>
                            <div class="detail-item-val" style="font-weight: normal; color: var(--text-secondary);">${escapeHtml(p.mark || '无备注')}</div>
                        </div>
                    </div>

                    <!-- Bottom Right: Recent Transactions -->
                    <div class="detail-block">
                        <div class="detail-block-title">
                            <span><i class="fa-solid fa-clock-rotate-left"></i> 最近出入库流转 (最新5条)</span>
                        </div>
                        <div class="detail-logs-table-wrapper" id="detail-logs-${prefix}-${p.id}">
                            <div class="loading-spinner" style="padding: 6px; font-size: 11px;"><i class="fa-solid fa-spinner fa-spin"></i> 加载流转流水...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function toggleProductDetail(productId, isMobile = false) {
    const p = state.currentProducts ? state.currentProducts[productId] : null;
    if (!p) return;

    if (!isMobile) {
        // Desktop Table Toggle
        const tr = document.querySelector(`.product-table-row[data-id="${productId}"]`);
        const expandTr = document.querySelector(`tr[data-expand-for="${productId}"]`);
        const container = document.getElementById(`expand-content-desktop-${productId}`);
        
        if (!tr || !expandTr || !container) return;

        const isCurrentlyExpanded = tr.classList.contains('is-expanded');

        // 1. Collapse all currently expanded desktop rows
        document.querySelectorAll('.product-table-row.is-expanded').forEach(otherRow => {
            otherRow.classList.remove('is-expanded');
            const otherId = otherRow.getAttribute('data-id');
            const otherExpandTr = document.querySelector(`tr[data-expand-for="${otherId}"]`);
            if (otherExpandTr) {
                otherExpandTr.style.display = 'none';
            }
        });

        // 2. If it was not expanded before, expand it now
        if (!isCurrentlyExpanded) {
            tr.classList.add('is-expanded');
            expandTr.style.display = 'table-row';
            
            // Populate content if not already populated
            if (!container.hasChildNodes() || container.innerHTML.trim() === '') {
                container.innerHTML = buildProductDetailPanelHtml(p, 'desktop');
                loadExpandedImagesAndLogs(productId, 'desktop');
                attachAccordionInnerEvents(container);
            }
        }
    } else {
        // Mobile Card Toggle
        const card = document.querySelector(`.compact-product-card[data-id="${productId}"]`);
        const body = document.getElementById(`expand-content-mobile-${productId}`);
        
        if (!card || !body) return;

        const isCurrentlyExpanded = card.classList.contains('is-expanded');

        // 1. Collapse all currently expanded mobile cards
        document.querySelectorAll('.compact-product-card.is-expanded').forEach(otherCard => {
            otherCard.classList.remove('is-expanded');
            const otherId = otherCard.getAttribute('data-id');
            const otherBody = document.getElementById(`expand-content-mobile-${otherId}`);
            if (otherBody) {
                otherBody.style.display = 'none';
            }
        });

        // 2. If it was not expanded before, expand it now
        if (!isCurrentlyExpanded) {
            card.classList.add('is-expanded');
            body.style.display = 'flex';
            
            // Populate content if not already populated
            if (!body.hasChildNodes() || body.innerHTML.trim() === '') {
                body.innerHTML = buildProductDetailPanelHtml(p, 'mobile');
                loadExpandedImagesAndLogs(productId, 'mobile');
                attachAccordionInnerEvents(body);
            }
        }
    }
}

function loadExpandedImagesAndLogs(productId, prefix) {
    // 1. Fetch images
    const imgContainer = document.getElementById(`detail-images-${prefix}-${productId}`);
    if (imgContainer) {
        fetch(`api/products.php?action=images&product_id=${productId}`)
            .then(res => res.json())
            .then(images => {
                imgContainer.innerHTML = '';
                const p = state.currentProducts ? state.currentProducts[productId] : null;
                
                // Build complete list of image URLs
                let imgList = [];
                if (Array.isArray(images) && images.length > 0) {
                    imgList = images.map(img => img.image_path);
                } else if (p && p.image) {
                    imgList = [p.image];
                }

                if (imgList.length === 0) {
                    if (prefix === 'mobile') {
                        const block = imgContainer.closest('.detail-block');
                        if (block) block.style.display = 'none';
                    } else {
                        imgContainer.innerHTML = `
                            <div class="gallery-no-image">
                                <i class="fa-regular fa-image"></i>
                                <span>暂未上传商品图片</span>
                            </div>
                        `;
                    }
                    return;
                }

                // Render Top Main Large Preview + Bottom Thumbnail Strip
                const galleryWrapper = document.createElement('div');
                galleryWrapper.className = 'detail-gallery-wrapper';
                
                const mainContainer = document.createElement('div');
                mainContainer.className = 'gallery-main-container';
                mainContainer.innerHTML = `
                    <img class="gallery-main-img" src="${imgList[0]}" alt="商品图片预览" title="点击全屏放大预览">
                `;

                // Zoom main image on click
                mainContainer.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const currentImg = mainContainer.querySelector('.gallery-main-img');
                    if (currentImg) {
                        zoomImage(currentImg.src);
                    }
                });

                // Thumbnail index container
                const thumbsContainer = document.createElement('div');
                thumbsContainer.className = 'gallery-thumbs-container';

                imgList.forEach((src, idx) => {
                    const thumbItem = document.createElement('div');
                    thumbItem.className = `gallery-thumb-item ${idx === 0 ? 'active' : ''}`;
                    thumbItem.setAttribute('data-src', src);
                    thumbItem.innerHTML = `<img src="${src}" alt="缩略图 ${idx + 1}">`;

                    // Click / Hover thumbnail to switch top preview image
                    const selectThumb = (e) => {
                        e.stopPropagation();
                        thumbsContainer.querySelectorAll('.gallery-thumb-item').forEach(el => el.classList.remove('active'));
                        thumbItem.classList.add('active');
                        const mainImg = mainContainer.querySelector('.gallery-main-img');
                        if (mainImg && mainImg.src !== src) {
                            mainImg.style.opacity = '0.5';
                            setTimeout(() => {
                                mainImg.src = src;
                                mainImg.style.opacity = '1';
                            }, 60);
                        }
                    };

                    thumbItem.addEventListener('click', selectThumb);
                    thumbItem.addEventListener('mouseenter', selectThumb);
                    thumbsContainer.appendChild(thumbItem);
                });

                galleryWrapper.appendChild(mainContainer);
                galleryWrapper.appendChild(thumbsContainer);
                imgContainer.appendChild(galleryWrapper);
            })
            .catch(() => {
                if (prefix === 'mobile') {
                    const block = imgContainer.closest('.detail-block');
                    if (block) block.style.display = 'none';
                } else {
                    imgContainer.innerHTML = '<span class="detail-no-image" style="color: var(--danger-color);">加载图片失败</span>';
                }
            });
    }

    // 2. Fetch recent 5 logs
    const logContainer = document.getElementById(`detail-logs-${prefix}-${productId}`);
    if (logContainer) {
        fetch(`api/stock.php?product_id=${productId}&limit=5`)
            .then(res => res.json())
            .then(data => {
                logContainer.innerHTML = '';
                const logs = data.logs || [];
                if (logs.length === 0) {
                    if (prefix === 'mobile') {
                        const block = logContainer.closest('.detail-block');
                        if (block) block.style.display = 'none';
                    } else {
                        logContainer.innerHTML = '<div style="font-size: 11px; color: var(--text-light); padding: 8px 0;"><i class="fa-solid fa-circle-info"></i> 暂无出入库流转记录</div>';
                    }
                    return;
                }

                const table = document.createElement('table');
                table.className = 'detail-logs-table';
                table.innerHTML = `
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>类型</th>
                            <th style="text-align: right;">变动数量</th>
                            <th>操作人</th>
                            <th>备注/单号</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                `;

                const tbody = table.querySelector('tbody');
                logs.forEach(log => {
                    let typeTag = '<span class="log-type-tag in">入库</span>';
                    let qtySigned = `+${log.quantity}`;
                    let qtyColor = 'var(--success-color)';

                    if (log.type === 'out') {
                        typeTag = '<span class="log-type-tag out">出库</span>';
                        qtySigned = `${log.quantity}`;
                        qtyColor = 'var(--danger-color)';
                    } else if (log.type === 're') {
                        typeTag = '<span class="log-type-tag re">退货</span>';
                        qtySigned = `+${log.quantity}`;
                        qtyColor = 'var(--warning-color)';
                    } else if (log.type === 'del') {
                        typeTag = '<span class="log-type-tag del">删除</span>';
                        qtySigned = `${log.quantity}`;
                        qtyColor = 'var(--text-light)';
                    }

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="font-size: 11px; color: var(--text-light);">${escapeHtml(log.created_at)}</td>
                        <td>${typeTag}</td>
                        <td style="text-align: right; font-weight: 700; color: ${qtyColor};">${qtySigned}</td>
                        <td style="font-size: 11px;">${escapeHtml(log.operator_name || '系统')}</td>
                        <td style="font-size: 11px; color: var(--text-light); max-width: 140px; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(log.remark || '--')}</td>
                    `;
                    tbody.appendChild(tr);
                });

                logContainer.appendChild(table);
            })
            .catch(() => {
                if (prefix === 'mobile') {
                    const block = logContainer.closest('.detail-block');
                    if (block) block.style.display = 'none';
                } else {
                    logContainer.innerHTML = '<div style="font-size: 11px; color: var(--danger-color); padding: 8px 0;">加载出入库流水失败</div>';
                }
            });
    }
}

function attachAccordionInnerEvents(container) {
    // Copy barcode button
    container.querySelectorAll('.copy-barcode-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const barcode = e.currentTarget.getAttribute('data-barcode');
            copyBarcodeToClipboard(barcode);
        });
    });
}

function copyBarcodeToClipboard(text) {
    if (!text) return;
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showToast(`条码已复制: ${text}`);
        }).catch(() => {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const input = document.createElement('input');
    input.value = text;
    document.body.appendChild(input);
    input.select();
    try {
        document.execCommand('copy');
        showToast(`条码已复制: ${text}`);
    } catch (e) {
        showToast('条码: ' + text);
    }
    document.body.removeChild(input);
}

function attachProductCardEvents() {
    // Desktop Row click -> toggle accordion
    document.querySelectorAll('.product-table-row.product-row-clickable').forEach(row => {
        row.addEventListener('click', (e) => {
            // Ignore if clicked on buttons or images
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('img')) {
                return;
            }
            const id = row.getAttribute('data-id');
            toggleProductDetail(id, false);
        });
    });

    // Mobile Card Header click -> toggle accordion
    document.querySelectorAll('.compact-product-card-header').forEach(header => {
        header.addEventListener('click', (e) => {
            // Ignore if clicked on buttons or images
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('img')) {
                return;
            }
            const id = header.getAttribute('data-id');
            toggleProductDetail(id, true);
        });
    });

    // In/Out quick txn
    document.querySelectorAll('.adjust-stock-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = e.currentTarget.getAttribute('data-id');
            openQuickTransactionModal(id);
        });
    });
    
    // Edit item details
    document.querySelectorAll('.edit-item-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = e.currentTarget.getAttribute('data-id');
            openProductFormModal(id);
        });
    });
    
    // Delete item
    document.querySelectorAll('.delete-item-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = e.currentTarget.getAttribute('data-id');
            if (confirm('确认删除此商品吗？该操作不可恢复！')) {
                fetch(`api/products.php?id=${id}`, { method: 'DELETE' })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showToast('商品已成功删除');
                            loadProductsList();
                        }
                    })
                    .catch(() => showToast('删除失败'));
            }
        });
    });

    // Image zoom click in product list
    document.querySelectorAll('.table-thumb').forEach(thumb => {
        thumb.addEventListener('click', (e) => {
            e.stopPropagation();
            zoomImage(thumb.src);
        });
    });
}

function renderPagination(elementId, pagination, onPageClick) {
    const container = document.getElementById(elementId);
    container.innerHTML = '';
    
    const curr = pagination.current_page;
    const tot = pagination.total_pages;
    
    if (tot <= 1) return; // No pagination needed
    
    // Prev button
    const prevBtn = document.createElement('button');
    prevBtn.className = `page-btn ${curr === 1 ? 'disabled' : ''}`;
    prevBtn.innerHTML = '<i class="fa-solid fa-angle-left"></i>';
    prevBtn.addEventListener('click', () => onPageClick(curr - 1));
    container.appendChild(prevBtn);
    
    // Pages
    for (let i = 1; i <= tot; i++) {
        // Show truncated pages on mobile to save space
        if (tot > 5) {
            if (i !== 1 && i !== tot && Math.abs(i - curr) > 1) {
                if (i === 2 && curr > 3) {
                    const dots = document.createElement('span');
                    dots.textContent = '...';
                    container.appendChild(dots);
                } else if (i === tot - 1 && curr < tot - 2) {
                    const dots = document.createElement('span');
                    dots.textContent = '...';
                    container.appendChild(dots);
                }
                continue;
            }
        }
        
        const btn = document.createElement('button');
        btn.className = `page-btn ${curr === i ? 'active' : ''}`;
        btn.textContent = i;
        btn.addEventListener('click', () => onPageClick(i));
        container.appendChild(btn);
    }
    
    // Next button
    const nextBtn = document.createElement('button');
    nextBtn.className = `page-btn ${curr === tot ? 'disabled' : ''}`;
    nextBtn.innerHTML = '<i class="fa-solid fa-angle-right"></i>';
    nextBtn.addEventListener('click', () => onPageClick(curr + 1));
    container.appendChild(nextBtn);
}

function setupSearchFilters() {
    // Low stock metric card click
    document.getElementById('metric-low-stock-card').addEventListener('click', () => {
        state.filterLowStockOnly = true;
        window.location.hash = '#products';
    });
    
    // Dashboard search input redirects to Products tab and executes search
    const dashboardSearch = document.getElementById('dashboard-search-input');
    if (dashboardSearch) {
        dashboardSearch.addEventListener('input', (e) => {
            const query = e.target.value;
            const productSearch = document.getElementById('product-search-input');
            if (productSearch) {
                productSearch.value = query;
                state.filterLowStockOnly = false; // Reset low stock filter
                window.location.hash = '#products';
                
                // Trigger products list load
                state.productsPagination.page = 1;
                loadProductsList();
                
                // Set cursor focus and selection to end of input
                setTimeout(() => {
                    productSearch.focus();
                    const len = productSearch.value.length;
                    productSearch.setSelectionRange(len, len);
                }, 100);
            }
            // Clear homepage search box input value
            e.target.value = '';
        });
    }
    
    // Search input typing debounce
    let searchTimeout;
    const searchInput = document.getElementById('product-search-input');
    searchInput.addEventListener('input', () => {
        state.filterLowStockOnly = false; // Reset low stock filter on manual typing
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            state.productsPagination.page = 1;
            loadProductsList();
        }, 400);
    });
    
    // Filters selection
    document.getElementById('product-filter-brand').addEventListener('change', () => {
        state.productsPagination.page = 1;
        loadProductsList();
    });
    
    document.getElementById('product-filter-local').addEventListener('change', () => {
        state.productsPagination.page = 1;
        loadProductsList();
    });
    
    // Logs Search/Filter
    const logSearchInput = document.getElementById('log-search-input');
    logSearchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            state.logsPagination.page = 1;
            loadLogsList();
        }, 400);
    });
    
    document.getElementById('log-filter-type').addEventListener('change', () => {
        state.logsPagination.page = 1;
        loadLogsList();
    });

    // Transaction product search inputs filter
    const txnSearchInput = document.getElementById('txn-product-search');
    if (txnSearchInput) {
        let txnSearchTimeout;
        txnSearchInput.addEventListener('input', () => {
            clearTimeout(txnSearchTimeout);
            txnSearchTimeout = setTimeout(() => {
                fetchProductsForTxnSelect(txnSearchInput.value.trim());
            }, 300);
        });
    }
}

/* --------------------------------------------------
 * 7. History/Audit Logs Loading
 * -------------------------------------------------- */
function loadLogsList() {
    const search = document.getElementById('log-search-input').value;
    const type = document.getElementById('log-filter-type').value;
    const page = state.logsPagination.page;
    
    fetch(`api/stock.php?page=${page}&limit=20&search=${encodeURIComponent(search)}&type=${type}`)
        .then(res => res.json())
        .then(data => {
            state.logsPagination = data.pagination;
            const container = document.getElementById('logs-timeline-container');
            container.innerHTML = '';
            
            if (data.logs.length === 0) {
                container.innerHTML = '<div class="loading-spinner">没有找到库存流转日志</div>';
                document.getElementById('logs-pagination').innerHTML = '';
                return;
            }
            
            data.logs.forEach(log => {
                const card = document.createElement('div');
                
                let typeClass = 'log-in';
                let typeStr = '入库';
                let tagClass = 'in';
                let qtySigned = `+${log.quantity}`;
                let qtyClass = 'qty-plus';
                
                if (log.type === 'out') {
                    typeClass = 'log-out';
                    typeStr = '出库';
                    tagClass = 'out';
                    qtySigned = `${log.quantity}`;
                    qtyClass = 'qty-minus';
                } else if (log.type === 're') {
                    typeClass = 'log-re';
                    typeStr = '退货';
                    tagClass = 're';
                    qtySigned = `+${log.quantity}`;
                    qtyClass = 'qty-plus';
                } else if (log.type === 'del') {
                    typeClass = 'log-del';
                    typeStr = '删除';
                    tagClass = 'del';
                    qtySigned = `${log.quantity}`;
                    qtyClass = 'qty-minus';
                }
                
                card.className = `timeline-card ${typeClass}`;
                
                let prodDetails = log.history_name;
                if (log.history_model) {
                    prodDetails += ` (${log.history_model})`;
                }
                
                card.innerHTML = `
                    <div class="timeline-header">
                        <div class="timeline-tag-area">
                            <span class="log-type-tag ${tagClass}">${typeStr}</span>
                        </div>
                        <span class="timeline-time">${log.created_at}</span>
                    </div>
                    <div class="timeline-body">
                        <div class="timeline-info">
                            <h4>${prodDetails}</h4>
                            <p class="operator-tag">操作人: ${log.operator_name || '系统'}</p>
                        </div>
                        <div class="timeline-qty ${qtyClass}">${qtySigned}</div>
                    </div>
                `;
                container.appendChild(card);
            });
            
            // Render pagination controls
            renderPagination('logs-pagination', state.logsPagination, (targetPage) => {
                state.logsPagination.page = targetPage;
                loadLogsList();
            });
        });
}

/* --------------------------------------------------
 * 8. Dialog Modals Actions (Add/Edit Forms, Photos, Scanner)
 * -------------------------------------------------- */
function setupModals() {
    // Open product modal
    document.getElementById('add-product-btn').addEventListener('click', () => {
        openProductFormModal();
    });

    // Export products button
    const exportBtn = document.getElementById('export-products-btn');
    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            const search = document.getElementById('product-search-input')?.value || '';
            const brand = document.getElementById('product-filter-brand')?.value || '';
            const local = document.getElementById('product-filter-local')?.value || '';
            const lowStock = state.filterLowStockOnly ? '1' : '0';

            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (brand) params.append('brand', brand);
            if (local) params.append('local', local);
            if (lowStock === '1') params.append('low_stock', '1');

            const url = `api/export_products.php?${params.toString()}`;
            showToast('正在导出商品数据表格...');
            window.location.href = url;
        });
    }

    // Import products modal and form
    const importBtn = document.getElementById('import-products-btn');
    const importForm = document.getElementById('import-products-form');
    const importFileInput = document.getElementById('import-file-input');
    const importResultBox = document.getElementById('import-result-box');
    const importErrorBox = document.getElementById('import-error-box');
    const importSubmitBtn = document.getElementById('import-submit-btn');

    if (importBtn) {
        importBtn.addEventListener('click', () => {
            if (importForm) importForm.reset();
            if (importResultBox) {
                importResultBox.classList.add('hidden');
                importResultBox.innerHTML = '';
            }
            if (importErrorBox) {
                importErrorBox.classList.add('hidden');
                importErrorBox.innerHTML = '';
            }
            openModal('import-products-modal');
        });
    }

    if (importForm) {
        importForm.addEventListener('submit', (e) => {
            e.preventDefault();
            if (!importFileInput || !importFileInput.files || importFileInput.files.length === 0) {
                showToast('请选择要导入的 CSV 表格文件');
                return;
            }

            const file = importFileInput.files[0];
            const mode = document.querySelector('input[name="import-mode"]:checked')?.value || 'skip';
            const autoBarcode = document.getElementById('import-auto-barcode')?.checked ? '1' : '0';

            const formData = new FormData();
            formData.append('file', file);
            formData.append('mode', mode);
            formData.append('auto_barcode', autoBarcode);

            if (importSubmitBtn) {
                importSubmitBtn.disabled = true;
                importSubmitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 正在导入解析中...';
            }
            if (importResultBox) importResultBox.classList.add('hidden');
            if (importErrorBox) importErrorBox.classList.add('hidden');

            fetch('api/import_products.php?action=import', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (importSubmitBtn) {
                    importSubmitBtn.disabled = false;
                    importSubmitBtn.innerHTML = '<i class="fa-solid fa-upload"></i> 开始导入';
                }

                if (data.success) {
                    if (importResultBox) {
                        let msg = `<strong><i class="fa-solid fa-circle-check"></i> ${escapeHtml(data.message)}</strong>`;
                        if (data.errors && data.errors.length > 0) {
                            msg += `<ul style="margin-top: 8px; padding-left: 18px; font-size: 12px;">` +
                                data.errors.map(err => `<li>${escapeHtml(err)}</li>`).join('') + `</ul>`;
                        }
                        importResultBox.innerHTML = msg;
                        importResultBox.classList.remove('hidden');
                    }
                    showToast('商品导入成功！');
                    loadProductsList();
                    loadSettings(); // refresh unit/brand presets if updated
                } else {
                    if (importErrorBox) {
                        importErrorBox.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${escapeHtml(data.error || '导入失败')}`;
                        importErrorBox.classList.remove('hidden');
                    }
                    showToast(data.error || '导入失败');
                }
            })
            .catch(err => {
                if (importSubmitBtn) {
                    importSubmitBtn.disabled = false;
                    importSubmitBtn.innerHTML = '<i class="fa-solid fa-upload"></i> 开始导入';
                }
                if (importErrorBox) {
                    importErrorBox.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> 请求异常: ${escapeHtml(err.message || '网络或服务端错误')}`;
                    importErrorBox.classList.remove('hidden');
                }
                showToast('导入请求失败');
            });
        });
    }

    // Combobox toggle buttons
    document.querySelectorAll('.combobox-toggle-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const targetId = btn.getAttribute('data-target');
            const targetMenu = document.getElementById(targetId);
            const isShown = targetMenu && targetMenu.classList.contains('show');

            document.querySelectorAll('.combobox-dropdown-menu').forEach(m => m.classList.remove('show'));

            if (targetMenu && !isShown) {
                const parentInput = btn.closest('.combobox-wrapper')?.querySelector('input');
                if (parentInput && parentInput._renderCombobox) {
                    parentInput._renderCombobox('', false);
                }
                targetMenu.classList.add('show');
            }
        });
    });

    // Close dropdowns on outside click
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.combobox-wrapper')) {
            document.querySelectorAll('.combobox-dropdown-menu').forEach(m => m.classList.remove('show'));
        }
    });

    // Dashboard quick action shortcuts
    const quickInBtn = document.getElementById('quick-in-btn');
    const quickOutBtn = document.getElementById('quick-out-btn');
    if (quickInBtn) {
        quickInBtn.addEventListener('click', () => {
            openQuickTransactionModal(null, 'in');
        });
    }
    if (quickOutBtn) {
        quickOutBtn.addEventListener('click', () => {
            openQuickTransactionModal(null, 'out');
        });
    }
    
    // Close Modals buttons triggers
    document.querySelectorAll('.modal-close, .modal-cancel-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const modal = e.target.closest('.modal');
            if (modal) closeModal(modal.id);
        });
    });
    
    // Segment controller actions for txn modal (updates submit button text)
    const quickTxnSubmit = document.getElementById('quick-txn-submit-btn');
    document.querySelectorAll('input[name="txn-type"]').forEach(radio => {
        radio.addEventListener('change', (e) => {
            const type = e.target.value;
            if (type === 'in') {
                quickTxnSubmit.textContent = '确认入库';
                quickTxnSubmit.className = 'btn btn-primary';
            } else if (type === 'out') {
                quickTxnSubmit.textContent = '确认出库';
                quickTxnSubmit.className = 'btn btn-primary btn-danger';
            } else if (type === 're') {
                quickTxnSubmit.textContent = '确认退货';
                quickTxnSubmit.className = 'btn btn-primary';
            }
        });
    });
    
    // Image Upload triggers
    const trigger = document.getElementById('image-upload-trigger-area');
    const fileInput = document.getElementById('image-file-input');
    const cameraTrigger = document.getElementById('camera-upload-trigger-area');
    const cameraInput = document.getElementById('camera-file-input');
    
    const uploadFunc = (inputEl) => {
        const prodId = document.getElementById('prod-id').value;
        if (!prodId) {
            showToast('请先保存商品，然后再上传关联图片');
            return;
        }
        
        if (inputEl.files.length === 0) return;
        
        const formData = new FormData();
        formData.append('product_id', prodId);
        formData.append('image', inputEl.files[0]);
        
        fetch('api/products.php?action=upload_image', {
            method: 'POST',
            body: formData
        })
        .then(res => {
            if (!res.ok) {
                return res.json().then(err => { throw new Error(err.error); });
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                showToast('图片上传成功');
                loadProductImages(prodId);
                inputEl.value = ''; // clear input
            }
        })
        .catch(err => showToast(err.message));
    };
    
    if (trigger && fileInput) {
        trigger.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => uploadFunc(fileInput));
    }
    
    if (cameraTrigger && cameraInput) {
        cameraTrigger.addEventListener('click', () => cameraInput.click());
        cameraInput.addEventListener('change', () => uploadFunc(cameraInput));
    }
    
    // Scanner simulation actions
    const scanBarcodeBtn = document.getElementById('scan-barcode-btn');
    const shortcutScanBtn = document.getElementById('barcode-scan-shortcut');
    const quickScanBtn = document.getElementById('quick-scan-btn');
    
    // Save target elements where barcode should go
    let scanTargetInput = null;
    
    const openScanner = (targetInput) => {
        scanTargetInput = targetInput;
        openModal('barcode-scan-modal');
        document.getElementById('simulated-barcode-val').value = '';
    };
    
    const generateBarcodeBtn = document.getElementById('generate-barcode-btn');
    if (generateBarcodeBtn) {
        generateBarcodeBtn.addEventListener('click', () => {
            const barcode = generateUniqueBarcode();
            const input = document.getElementById('prod-barcode');
            if (input) {
                input.value = barcode;
                showToast(`已生成新条码: ${barcode}`);
            }
        });
    }

    if (scanBarcodeBtn) {
        scanBarcodeBtn.addEventListener('click', () => {
            openScanner(document.getElementById('prod-barcode'));
        });
    }
    
    if (shortcutScanBtn) {
        shortcutScanBtn.addEventListener('click', () => {
            openScanner(document.getElementById('product-search-input'));
        });
    }
    
    if (quickScanBtn) {
        quickScanBtn.addEventListener('click', () => {
            openScanner(null); // Direct dashboard lookup
        });
    }
    
    document.getElementById('simulated-scan-confirm-btn').addEventListener('click', () => {
        const barcode = document.getElementById('simulated-barcode-val').value.trim();
        if (!barcode) {
            showToast('请输入模拟条码进行扫描');
            return;
        }
        
        closeModal('barcode-scan-modal');
        
        if (scanTargetInput) {
            scanTargetInput.value = barcode;
            // trigger input event in search
            if (scanTargetInput.id === 'product-search-input') {
                scanTargetInput.dispatchEvent(new Event('input'));
            }
            showToast(`已成功录入条码: ${barcode}`);
        } else {
            // Dashboard quick scan: search product by barcode and open stock adjust
            fetch(`api/products.php?limit=1&search=${encodeURIComponent(barcode)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.products && data.products.length > 0) {
                        const prod = data.products[0];
                        showToast(`扫码找到商品: ${prod.name}`);
                        openQuickTransactionModal(prod.id);
                    } else {
                        showToast('未找到该条码对应的商品，是否新增？');
                        openProductFormModal(null, barcode);
                    }
                });
        }
    });

    // Open user modal
    const addUserBtn = document.getElementById('settings-add-user-btn');
    if (addUserBtn) {
        addUserBtn.addEventListener('click', () => {
            openUserModal();
        });
    }

    // Barcode batch maintenance actions
    const checkBarcodesBtn = document.getElementById('btn-check-barcodes');
    const fillBarcodesBtn = document.getElementById('btn-fill-barcodes');
    
    if (checkBarcodesBtn) {
        checkBarcodesBtn.addEventListener('click', () => {
            loadBarcodeStats(true);
        });
    }
    
    if (fillBarcodesBtn) {
        fillBarcodesBtn.addEventListener('click', () => {
            if (confirm('确认一键为系统中所有缺失条码的商品自动生成标准 EAN-13 格式条形码吗？')) {
                fillBarcodesBtn.disabled = true;
                fillBarcodesBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 正在批量生成补全中...';
                
                fetch('api/fill_barcodes.php?action=execute', { method: 'POST' })
                    .then(res => res.json())
                    .then(data => {
                        fillBarcodesBtn.disabled = false;
                        fillBarcodesBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> 一键自动补全缺失条码';
                        if (data.success) {
                            showToast(data.message || `成功补全 ${data.updated_count} 个条码！`);
                            loadBarcodeStats();
                        } else {
                            showToast(data.error || '补全条码失败');
                        }
                    })
                    .catch(() => {
                        fillBarcodesBtn.disabled = false;
                        fillBarcodesBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> 一键自动补全缺失条码';
                        showToast('请求失败，请检查网络或后端');
                    });
            }
        });
    }
}

function loadBarcodeStats(notify = false) {
    const badge = document.getElementById('barcode-stats-badge');
    const card = document.getElementById('settings-barcodes-card');
    if (!badge) return;
    
    if (state.user && state.user.role === 'admin') {
        if (card) card.classList.remove('hidden');
    } else {
        if (card) card.classList.add('hidden');
        return;
    }
    
    fetch('api/fill_barcodes.php?action=check')
        .then(res => res.json())
        .then(data => {
            if (data.missing_barcodes > 0) {
                badge.innerHTML = `
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                        <span>商品总数: <strong>${data.total_products}</strong> 种，已有条码: <strong style="color: var(--success-color);">${data.filled_barcodes}</strong> 种</span>
                        <span style="background: var(--danger-light); color: var(--danger-color); font-weight: 700; padding: 4px 8px; border-radius: 4px; font-size: 12px;"><i class="fa-solid fa-triangle-exclamation"></i> 待补全条码: ${data.missing_barcodes} 种</span>
                    </div>
                `;
            } else {
                badge.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 8px; color: var(--success-color); font-weight: 600;">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>全部 ${data.total_products} 种商品均已有条形码，数据完整！</span>
                    </div>
                `;
            }
            if (notify) {
                showToast(`条码检测完成: 待补全 ${data.missing_barcodes} 个`);
            }
        })
        .catch(() => {
            badge.innerHTML = '<span style="color: var(--danger-color);"><i class="fa-solid fa-triangle-exclamation"></i> 读取条码统计失败</span>';
        });
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('open');
        // lock body scroll on mobile
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }
}

function openProductFormModal(productId = null, barcodePreFill = null) {
    const title = document.getElementById('product-modal-title');
    const form = document.getElementById('product-form');
    const stockGroup = document.getElementById('prod-stock-group');
    const stockInput = document.getElementById('prod-stock');
    const stockLabel = stockGroup ? stockGroup.querySelector('label') : null;
    const uploader = document.querySelector('.image-uploader-section');
    
    form.reset();
    document.getElementById('prod-id').value = '';
    document.querySelectorAll('#product-images-list .image-preview-item').forEach(item => item.remove());
    
    if (barcodePreFill) {
        document.getElementById('prod-barcode').value = barcodePreFill;
    }
    
    // Always keep stock group visible
    if (stockGroup) {
        stockGroup.classList.remove('hidden');
    }
    
    // Ensure datalist suggestions are refreshed
    populateSuggestions();
    
    if (productId) {
        title.textContent = '编辑商品详情';
        if (stockLabel) stockLabel.textContent = '当前库存';
        
        // If settings disable editing stock directly, make it read-only
        if (stockInput) {
            stockInput.readOnly = (state.settings.allowEditStock !== 'true');
        }
        
        // Fetch specific details directly by ID
        fetch(`api/products.php?id=${productId}`)
            .then(res => res.json())
            .then(p => {
                if (p) {
                    document.getElementById('prod-id').value = p.id;
                    document.getElementById('prod-name').value = p.name;
                    document.getElementById('prod-model').value = p.model;
                    document.getElementById('prod-spec').value = p.spec;
                    document.getElementById('prod-barcode').value = p.barcode;
                    document.getElementById('prod-unit').value = p.unit;
                    document.getElementById('prod-brand').value = p.brand;
                    document.getElementById('prod-local').value = p.local;
                    document.getElementById('prod-price').value = p.price;
                    document.getElementById('prod-mark').value = p.mark;
                    
                    if (stockInput) {
                        stockInput.value = p.stock;
                    }
                    
                    uploader.classList.remove('hidden');
                    loadProductImages(p.id);
                }
            });
    } else {
        title.textContent = '新增商品';
        if (stockLabel) stockLabel.textContent = '初始库存';
        if (stockInput) {
            stockInput.readOnly = false;
            stockInput.value = '0';
        }
        uploader.classList.add('hidden'); // Image requires item ID, so hide until saved.

        // Automatically prefill first default unit from presets
        const unitInput = document.getElementById('prod-unit');
        if (unitInput) {
            unitInput.value = (state.suggestions.units && state.suggestions.units.length > 0) ? state.suggestions.units[0] : '个';
        }

        // Clean brand and local inputs
        const brandInput = document.getElementById('prod-brand');
        if (brandInput) brandInput.value = '';
        const localInput = document.getElementById('prod-local');
        if (localInput) localInput.value = '';

        // Automatically prefill unique barcode if not already provided
        const barcodeInput = document.getElementById('prod-barcode');
        if (barcodeInput) {
            barcodeInput.value = barcodePreFill || generateUniqueBarcode();
        }
    }
    
    openModal('product-modal');
}

function generateUniqueBarcode() {
    const now = new Date();
    const yy = String(now.getFullYear()).slice(-2);
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');
    const rand = String(Math.floor(100 + Math.random() * 900));
    const first12 = `690${yy}${mm}${dd}${rand}`;
    
    let sumOdd = 0;
    let sumEven = 0;
    for (let i = 0; i < 12; i++) {
        const num = parseInt(first12[i], 10);
        if (i % 2 === 0) {
            sumOdd += num;
        } else {
            sumEven += num;
        }
    }
    const total = sumOdd + sumEven * 3;
    const checkDigit = (10 - (total % 10)) % 10;
    return `${first12}${checkDigit}`;
}

function loadProductImages(productId) {
    fetch(`api/products.php?action=images&product_id=${productId}`)
        .then(res => res.json())
        .then(images => {
            const container = document.getElementById('product-images-list');
            if (!container) return;
            
            // Clear only existing preview thumbnails, keeping the upload trigger buttons intact
            container.querySelectorAll('.image-preview-item').forEach(item => item.remove());
            
            const firstTrigger = document.getElementById('image-upload-trigger-area');
            
            images.forEach(img => {
                const div = document.createElement('div');
                div.className = 'image-preview-item';
                div.innerHTML = `
                    <img src="${img.image_path}" alt="Image" style="cursor: pointer;">
                    <button type="button" class="del-img-btn" data-img-id="${img.id}"><i class="fa-solid fa-xmark"></i></button>
                `;
                
                // Attach zoom view action
                div.querySelector('img').addEventListener('click', () => {
                    zoomImage(img.image_path);
                });
                
                // Attach delete action
                div.querySelector('.del-img-btn').addEventListener('click', (e) => {
                    const imgId = e.currentTarget.getAttribute('data-img-id');
                    if (confirm('确认删除此商品图片吗？')) {
                        fetch(`api/products.php?action=delete_image&image_id=${imgId}`, { method: 'DELETE' })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    showToast('图片已成功删除');
                                    loadProductImages(productId);
                                }
                            });
                    }
                });
                
                if (firstTrigger) {
                    container.insertBefore(div, firstTrigger);
                } else {
                    container.appendChild(div);
                }
            });
        });
}

function openQuickTransactionModal(productId = null, defaultType = 'in') {
    const searchGroup = document.getElementById('txn-search-group');
    const resultsGroup = document.getElementById('txn-results-group');
    const searchInput = document.getElementById('txn-product-search');
    const preview = document.getElementById('txn-selected-product-preview');
    const hiddenId = document.getElementById('txn-selected-product-id');
    
    hiddenId.value = productId || '';
    
    if (productId) {
        // Pre-selected mode: Hide search input and result list, show preview card
        if (searchGroup) searchGroup.classList.add('hidden');
        if (resultsGroup) resultsGroup.classList.add('hidden');
        if (preview) {
            preview.classList.remove('hidden');
            preview.innerHTML = '<div class="loading-spinner"><i class="fa-solid fa-spinner fa-spin"></i> 加载中...</div>';
        }
        
        // Fetch specific product details directly
        fetch(`api/products.php?id=${productId}`)
            .then(res => res.json())
            .then(p => {
                if (preview && p) {
                    let text = p.name;
                    if (p.model) text += ` (${p.model})`;
                    preview.innerHTML = `
                        <div class="selected-prod-info">
                            <h4>已选商品</h4>
                            <p>${text}</p>
                        </div>
                        <div class="selected-prod-stock">
                            当前库存: ${p.stock} ${p.unit || '个'}
                        </div>
                    `;
                }
            });
    } else {
        // Fast transaction mode: Show search input and result list, hide preview card
        if (searchGroup) searchGroup.classList.remove('hidden');
        if (resultsGroup) resultsGroup.classList.remove('hidden');
        if (preview) {
            preview.classList.add('hidden');
            preview.innerHTML = '';
        }
        if (searchInput) searchInput.value = '';
        
        // Retrieve initial top products list
        fetchProductsForTxnSelect('');
    }
        
    document.getElementById('txn-quantity').value = '1';
    document.getElementById('txn-mark').value = '';
    
    // Set default segment based on defaultType argument
    const typeRadio = document.getElementById(`txn-type-${defaultType}`);
    if (typeRadio) {
        typeRadio.checked = true;
        typeRadio.dispatchEvent(new Event('change'));
    }
    
    openModal('quick-transaction-modal');
}

// Mobile top floating toast messages utility
function showToast(message) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.classList.add('show');
    
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

/* --------------------------------------------------
 * 9. User Accounts Management (Admin Only)
 * -------------------------------------------------- */
function loadUsersList() {
    const container = document.getElementById('settings-users-list');
    if (!container) return;
    
    container.innerHTML = '<div class="loading-spinner"><i class="fa-solid fa-spinner fa-spin"></i> 正在加载账号列表...</div>';
    
    fetch('api/users.php')
        .then(res => {
            if (!res.ok) {
                throw new Error('无法加载用户列表');
            }
            return res.json();
        })
        .then(users => {
            container.innerHTML = '';
            if (users.length === 0) {
                container.innerHTML = '<div class="loading-spinner">暂无账号</div>';
                return;
            }
            
            users.forEach(u => {
                const item = document.createElement('div');
                item.className = 'user-item';
                
                const roleText = u.role === 'admin' ? '系统管理员' : '普通操作员';
                const roleBadgeClass = u.role === 'admin' ? 'badge' : 'badge badge-secondary';
                
                // Prevent self-deletion
                const isSelf = u.id === state.user.id;
                const deleteBtnHtml = isSelf 
                    ? `<button type="button" class="btn btn-secondary btn-sm disabled" title="不能删除自己" disabled><i class="fa-solid fa-trash-can"></i> 删除</button>`
                    : `<button type="button" class="btn btn-secondary btn-sm delete-user-btn" data-id="${u.id}" data-username="${u.username}"><i class="fa-solid fa-trash-can"></i> 删除</button>`;
                
                item.innerHTML = `
                    <div class="user-item-info">
                        <h4>${u.username} ${isSelf ? '<span style="font-size:10px; opacity:0.6;">(当前登录)</span>' : ''}</h4>
                        <p><span class="${roleBadgeClass}">${roleText}</span></p>
                    </div>
                    <div class="user-item-actions">
                        <button type="button" class="btn btn-primary btn-sm edit-user-btn" data-id="${u.id}" data-username="${u.username}" data-role="${u.role}"><i class="fa-solid fa-pen-to-square"></i> 编辑</button>
                        ${deleteBtnHtml}
                    </div>
                `;
                container.appendChild(item);
            });
            
            attachUserListEvents();
        })
        .catch(err => {
            container.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
        });
}

function attachUserListEvents() {
    // Edit user click
    document.querySelectorAll('.edit-user-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const id = e.currentTarget.getAttribute('data-id');
            const username = e.currentTarget.getAttribute('data-username');
            const role = e.currentTarget.getAttribute('data-role');
            openUserModal(id, username, role);
        });
    });
    
    // Delete user click
    document.querySelectorAll('.delete-user-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const id = e.currentTarget.getAttribute('data-id');
            const username = e.currentTarget.getAttribute('data-username');
            
            if (confirm(`确认要删除用户账号 "${username}" 吗？此操作不可撤销！`)) {
                fetch(`api/users.php?id=${id}`, {
                    method: 'DELETE'
                })
                .then(res => {
                    if (!res.ok) {
                        return res.json().then(err => { throw new Error(err.error || '删除失败'); });
                    }
                    return res.json();
                })
                .then(data => {
                    if (data.success) {
                        showToast(`用户账号 "${username}" 已成功删除`);
                        loadUsersList();
                    }
                })
                .catch(err => showToast(err.message));
            }
        });
    });
}

function openUserModal(userId = null, username = '', role = 'user') {
    const title = document.getElementById('user-modal-title');
    const form = document.getElementById('user-form');
    const usernameInput = document.getElementById('user-form-username');
    const passwordInput = document.getElementById('user-form-password');
    const passwordLabel = document.querySelector('#user-form-password-group label');
    const roleSelect = document.getElementById('user-form-role');
    
    form.reset();
    document.getElementById('user-form-id').value = userId || '';
    
    if (userId) {
        title.textContent = '编辑用户账号';
        usernameInput.value = username;
        usernameInput.readOnly = true;
        roleSelect.value = role;
        passwordLabel.innerHTML = '密码 (留空表示不修改)';
        passwordInput.required = false;
        passwordInput.placeholder = '不修改请留空';
    } else {
        title.textContent = '新增用户账号';
        usernameInput.value = '';
        usernameInput.readOnly = false;
        roleSelect.value = 'user';
        passwordLabel.innerHTML = '密码 *';
        passwordInput.required = true;
        passwordInput.placeholder = '请输入密码，最少 6 位';
    }
    
    openModal('user-modal');
}

function zoomImage(src) {
    const viewerImg = document.getElementById('viewer-img');
    if (viewerImg) {
        viewerImg.src = src;
        openModal('image-viewer-modal');
    }
}

function fetchProductsForTxnSelect(query = '') {
    const listContainer = document.getElementById('txn-search-results-list');
    const hiddenIdInput = document.getElementById('txn-selected-product-id');
    if (!listContainer) return;
    
    listContainer.innerHTML = '<div class="loading-spinner" style="padding: 15px;"><i class="fa-solid fa-spinner fa-spin"></i> 正在检索商品...</div>';
    
    fetch(`api/products.php?limit=50&search=${encodeURIComponent(query)}`)
        .then(res => res.json())
        .then(data => {
            listContainer.innerHTML = '';
            if (!data.products || data.products.length === 0) {
                listContainer.innerHTML = '<div class="loading-spinner" style="padding: 15px;">无匹配商品</div>';
                return;
            }
            
            data.products.forEach(p => {
                const item = document.createElement('div');
                item.className = 'txn-search-result-item';
                item.setAttribute('data-id', p.id);
                
                item.innerHTML = `
                    <div class="result-info">
                        <span class="result-name">${p.name}</span>
                        <span class="result-model">${p.model || '无型号'} ${p.spec ? `/ ${p.spec}` : ''}</span>
                    </div>
                    <span class="result-stock">库存: ${p.stock} ${p.unit || '个'}</span>
                `;
                
                // If it is the currently selected product, add selected class
                if (hiddenIdInput.value === String(p.id)) {
                    item.classList.add('selected');
                }
                
                // Click to select
                item.addEventListener('click', () => {
                    listContainer.querySelectorAll('.txn-search-result-item').forEach(el => el.classList.remove('selected'));
                    item.classList.add('selected');
                    hiddenIdInput.value = p.id;
                });
                
                listContainer.appendChild(item);
            });
        })
        .catch(() => {
            listContainer.innerHTML = '<div class="loading-spinner" style="padding: 15px; color: var(--danger-color);">加载商品失败</div>';
        });
}
