{{--
    Top navbar (AdminLTE 4 / Bootstrap 5, no jQuery — ADR-010).
    User dropdown: Perfil (B08) and Cerrar sesión (POST logout).
--}}
<nav class="app-header navbar navbar-expand bg-body">
    <div class="container-fluid">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" href="#" data-lte-toggle="push-menu" role="button"
                   aria-label="Alternar menú lateral">
                    <i class="bi bi-list fs-4" aria-hidden="true"></i>
                </a>
            </li>
        </ul>

            <ul class="navbar-nav ms-auto">
                @auth
                    <li class="nav-item">
                        <a href="{{ route('notifications.index') }}" class="nav-link position-relative"
                           aria-label="Notificaciones" data-testid="nav-notifications">
                            <i class="bi bi-bell fs-5" aria-hidden="true"></i>
                            @php
                                $unreadNotifications = auth()->user()->unreadNotifications()->count();
                            @endphp
                            @if ($unreadNotifications > 0)
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                      data-testid="nav-unread-count">
                                    {{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}
                                    <span class="visually-hidden">notificaciones sin leer</span>
                                </span>
                            @endif
                        </a>
                    </li>
                @endauth
                <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <span class="d-none d-md-inline">{{ auth()->user()?->name }}</span>
                    <i class="bi bi-person-circle ms-1" aria-hidden="true"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li>
                        <a class="dropdown-item" href="#">
                            <i class="bi bi-person me-2" aria-hidden="true"></i>Perfil
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item">
                                <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Cerrar sesión
                            </button>
                        </form>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</nav>
