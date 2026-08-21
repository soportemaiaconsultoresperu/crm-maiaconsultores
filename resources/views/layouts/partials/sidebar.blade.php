{{--
    Sidebar navigation (AdminLTE 4). Module routes arrive with their
    blocks (B02+); placeholders point to "#" until then.
--}}
<aside class="app-sidebar">
    <div class="sidebar-brand">
        <a href="{{ route('dashboard') }}" class="app-logo text-decoration-none">
            {{ config('app.name', 'CRM Maia') }}
        </a>
    </div>

    <div class="sidebar-wrapper">
        <nav class="sidebar-nav">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                        <i class="nav-icon bi bi-speedometer2" aria-hidden="true"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
                    <li class="nav-item">
                        <a href="{{ route('leads.index') }}" class="nav-link {{ request()->routeIs('leads.*') ? 'active' : '' }}">
                            <i class="nav-icon bi bi-person-plus" aria-hidden="true"></i>
                            <p>Prospectos</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') ? 'active' : '' }}">
                            <i class="nav-icon bi bi-building" aria-hidden="true"></i>
                            <p>Clientes</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('contacts.index') }}" class="nav-link {{ request()->routeIs('contacts.*') ? 'active' : '' }}">
                            <i class="nav-icon bi bi-people" aria-hidden="true"></i><p>Contactos</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('opportunities.kanban') }}" class="nav-link {{ request()->routeIs('opportunities.*') ? 'active' : '' }}">
                            <i class="nav-icon bi bi-graph-up-arrow" aria-hidden="true"></i><p>Oportunidades</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('activities.index') }}" class="nav-link {{ request()->routeIs('activities.*') ? 'active' : '' }}">
                            <i class="nav-icon bi bi-check2-square" aria-hidden="true"></i><p>Actividades</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.campaign_runs.index') }}" class="nav-link {{ request()->routeIs('admin.campaign_*') ? 'active' : '' }}">
                            <i class="nav-icon bi bi-megaphone" aria-hidden="true"></i><p>Campañas</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('calendar.index') }}" class="nav-link {{ request()->routeIs('calendar.*') ? 'active' : '' }}">
                            <i class="nav-icon bi bi-calendar3" aria-hidden="true"></i><p>Calendario</p>
                        </a>
                    </li>
<li class="nav-item">
                    <a href="{{ route('products.index') }}" class="nav-link {{ request()->routeIs('products.*') ? 'active' : '' }}"><i class="nav-icon bi bi-box-seam" aria-hidden="true"></i><p>Productos</p></a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('quotations.index') }}" class="nav-link {{ request()->routeIs('quotations.*') ? 'active' : '' }}"><i class="nav-icon bi bi-file-earmark-text" aria-hidden="true"></i><p>Cotizaciones</p></a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link"><i class="nav-icon bi bi-folder2-open" aria-hidden="true"></i><p>Documentos</p></a>
                </li>
<li class="nav-item">
                        <a href="{{ route('reports.index') }}" class="nav-link {{ request()->routeIs('reports.*') ? 'active' : '' }}"><i class="nav-icon bi bi-clipboard-data" aria-hidden="true"></i><p>Reportes</p></a>
                    </li>
                    @php($adminPerms = ['users.view', 'teams.view', 'roles.view', 'catalogs.view', 'settings.view', 'audit.view', 'automations.view'])
                    @if (collect($adminPerms)->contains(fn ($p) => auth()->user()?->can($p)))
                        <li class="nav-header">Administración</li>

                        @can('users.view')
                            <li class="nav-item">
                                <a href="{{ route('admin.users.index') }}" class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}"><i class="nav-icon bi bi-people-fill" aria-hidden="true"></i><p>Usuarios</p></a>
                            </li>
                        @endcan

                        @can('teams.view')
                            <li class="nav-item">
                                <a href="{{ route('admin.teams.index') }}" class="nav-link {{ request()->routeIs('admin.teams.*') ? 'active' : '' }}"><i class="nav-icon bi bi-diagram-3" aria-hidden="true"></i><p>Equipos</p></a>
                            </li>
                        @endcan

                        @can('roles.view')
                            <li class="nav-item">
                                <a href="{{ route('admin.roles.index') }}" class="nav-link {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}"><i class="nav-icon bi bi-shield-lock" aria-hidden="true"></i><p>Roles</p></a>
                            </li>
                        @endcan

                        @can('catalogs.view')
                            <li class="nav-item">
                                <a href="{{ route('admin.catalogs.landing') }}" class="nav-link {{ request()->routeIs('admin.catalogs.*') ? 'active' : '' }}"><i class="nav-icon bi bi-list-ul" aria-hidden="true"></i><p>Catálogos</p></a>
                            </li>
                        @endcan

                        @can('settings.view')
                            <li class="nav-item">
                                <a href="{{ route('admin.settings.index') }}" class="nav-link {{ request()->routeIs('admin.settings.*') ? 'active' : '' }}"><i class="nav-icon bi bi-gear" aria-hidden="true"></i><p>Configuración</p></a>
                            </li>
                        @endcan

@can('audit.view')
                            <li class="nav-item">
                                <a href="{{ route('admin.audit.index') }}" class="nav-link {{ request()->routeIs('admin.audit.*') ? 'active' : '' }}"><i class="nav-icon bi bi-clock-history" aria-hidden="true"></i><p>Auditoría</p></a>
                            </li>
                        @endcan

                        @can('automations.view')
                            <li class="nav-item">
                                <a href="{{ route('admin.automations.index') }}" class="nav-link {{ request()->routeIs('admin.automations.*') ? 'active' : '' }}"><i class="nav-icon bi bi-lightning-charge" aria-hidden="true"></i><p>Automatizaciones</p></a>
                            </li>
                        @endcan
                    @endif
            </ul>
        </nav>
    </div>
</aside>
