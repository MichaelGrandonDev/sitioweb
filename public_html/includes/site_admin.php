<?php

declare(strict_types=1);

/**
 * Auth compartida del sitio: el login Administrador de AcademiaFluxus abre Galería, Blogs y Turnos.
 */
function site_admin_grant(): void
{
    $_SESSION['site_admin'] = true;
    $_SESSION['turnos_admin'] = true;
}

function site_admin_revoke(): void
{
    unset($_SESSION['site_admin'], $_SESSION['blogs_admin'], $_SESSION['galeria_admin'], $_SESSION['turnos_admin']);
}

function site_is_admin(): bool
{
    return !empty($_SESSION['site_admin'])
        || !empty($_SESSION['blogs_admin'])
        || !empty($_SESSION['galeria_admin']);
}
