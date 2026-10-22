<?php

namespace App\Support\Permissions;

use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Spanish, plain-language catalog of every Spatie permission
 * (odd/tasks/roles-permissions-ux.md, S2).
 *
 * The catalog only DESCRIBES permissions: names stored in Spatie stay exactly
 * as the seeders create them, so nothing about what a permission allows
 * changes here. Every permission created by a seeder or a service provider
 * must have an entry (tests/Feature/Admin/RolePermissionsUxTest.php fails
 * otherwise); unknown names found in the database fall back to a label
 * derived from the name inside the "Otros" module so they are never hidden.
 *
 * Read scopes follow the `<module>.view.own|team|any` convention: when all
 * three exist they are shown as a single "¿Qué registros puede ver?" choice.
 */
final class PermissionCatalog
{
    /** Role that bypasses every check (Gate::before in AuthServiceProvider). */
    public const FULL_ACCESS_ROLE = 'admin';

    public const SCOPES = ['own', 'team', 'any'];

    public const GROUPS = [
        'ver' => 'Ver',
        'editar' => 'Crear y editar',
        'especiales' => 'Acciones especiales',
        'administracion' => 'Administración',
    ];

    /** Short phrase used in readable summaries for each read scope. */
    public const SCOPE_PHRASES = [
        'own' => 'ver solo los suyos',
        'team' => 'ver los de su equipo',
        'any' => 'ver todos',
    ];

    /**
     * key => [Spanish name (as in the sidebar where one exists), bootstrap icon, order].
     */
    private const MODULES = [
        'leads' => ['Prospectos', 'bi-person-plus', 10],
        'customers' => ['Clientes', 'bi-building', 20],
        'contacts' => ['Contactos', 'bi-people', 30],
        'opportunities' => ['Oportunidades', 'bi-graph-up-arrow', 40],
        'activities' => ['Actividades', 'bi-check2-square', 50],
        'campaigns' => ['Campañas', 'bi-megaphone', 60],
        'calendar' => ['Calendario', 'bi-calendar3', 70],
        'course-talks' => ['Cursos y charlas', 'bi-mortarboard', 80],
        'support' => ['Soporte', 'bi-headset', 90],
        'content_projects' => ['Cronograma de contenidos', 'bi-calendar2-week', 100],
        'products' => ['Productos', 'bi-box-seam', 110],
        'quotations' => ['Cotizaciones', 'bi-file-earmark-text', 120],
        'documents' => ['Documentos', 'bi-folder2-open', 130],
        'reports' => ['Reportes', 'bi-clipboard-data', 140],
        'email' => ['Correo electrónico', 'bi-envelope', 150],
        'whatsapp' => ['WhatsApp', 'bi-whatsapp', 160],
        'notifications' => ['Notificaciones', 'bi-bell', 170],
        'consent' => ['Consentimientos', 'bi-shield-check', 180],
        'automations' => ['Automatizaciones', 'bi-robot', 190],
        'users' => ['Usuarios', 'bi-people-fill', 200],
        'teams' => ['Equipos', 'bi-diagram-3', 210],
        'roles' => ['Roles', 'bi-shield-lock', 220],
        'catalogs' => ['Catálogos', 'bi-list-ul', 230],
        'settings' => ['Configuración', 'bi-gear', 240],
        'audit' => ['Auditoría', 'bi-clock-history', 250],
        'demo-data' => ['Datos de demostración', 'bi-database-check', 260],
        'otros' => ['Otros', 'bi-three-dots', 999],
    ];

    /**
     * permission => [module, group, label, description, sensitive, short phrase for summaries].
     */
    private const ENTRIES = [
        // Prospectos
        'leads.view.any' => ['leads', 'ver', 'Ver todos los prospectos', 'Ve los prospectos de toda la empresa.', false, 'ver todos'],
        'leads.view.team' => ['leads', 'ver', 'Ver los prospectos de su equipo', 'Ve los prospectos asignados a cualquier miembro de su equipo.', false, 'ver los de su equipo'],
        'leads.view.own' => ['leads', 'ver', 'Ver solo sus prospectos', 'Ve únicamente los prospectos asignados a la persona.', false, 'ver solo los suyos'],
        'leads.create' => ['leads', 'editar', 'Crear prospectos', 'Registra prospectos nuevos.', false, 'crear'],
        'leads.update' => ['leads', 'editar', 'Editar prospectos', 'Modifica los datos de los prospectos que puede ver.', false, 'editar'],
        'leads.deactivate' => ['leads', 'editar', 'Desactivar prospectos', 'Da de baja prospectos sin borrarlos.', false, 'desactivar'],
        'leads.assign' => ['leads', 'especiales', 'Asignar prospectos', 'Cambia el responsable de un prospecto.', false, 'asignar'],
        'leads.convert' => ['leads', 'especiales', 'Convertir prospectos en clientes', 'Convierte un prospecto calificado en cliente.', false, 'convertir en cliente'],
        'leads.import' => ['leads', 'especiales', 'Importar prospectos', 'Carga prospectos masivamente desde un archivo.', true, 'importar'],
        'leads.export' => ['leads', 'especiales', 'Exportar prospectos', 'Descarga el listado de prospectos a un archivo.', true, 'exportar'],

        // Clientes
        'customers.view.any' => ['customers', 'ver', 'Ver todos los clientes', 'Ve los clientes de toda la empresa.', false, 'ver todos'],
        'customers.view.team' => ['customers', 'ver', 'Ver los clientes de su equipo', 'Ve los clientes asignados a cualquier miembro de su equipo.', false, 'ver los de su equipo'],
        'customers.view.own' => ['customers', 'ver', 'Ver solo sus clientes', 'Ve únicamente los clientes asignados a la persona.', false, 'ver solo los suyos'],
        'customers.create' => ['customers', 'editar', 'Crear clientes', 'Registra clientes nuevos.', false, 'crear'],
        'customers.update' => ['customers', 'editar', 'Editar clientes', 'Modifica los datos de los clientes que puede ver.', false, 'editar'],
        'customers.deactivate' => ['customers', 'editar', 'Desactivar clientes', 'Da de baja clientes sin borrarlos.', false, 'desactivar'],
        'customers.export' => ['customers', 'especiales', 'Exportar clientes', 'Descarga el listado de clientes a un archivo.', true, 'exportar'],
        'customer-payments.view' => ['customers', 'especiales', 'Ver pagos y facturas de clientes', 'Consulta la información financiera de los clientes.', false, 'ver pagos'],
        'customer-payments.manage' => ['customers', 'especiales', 'Gestionar pagos y facturas de clientes', 'Registra y modifica facturas y pagos de clientes.', true, 'gestionar pagos'],

        // Contactos
        'contacts.view.any' => ['contacts', 'ver', 'Ver todos los contactos', 'Ve los contactos de toda la empresa.', false, 'ver todos'],
        'contacts.view.team' => ['contacts', 'ver', 'Ver los contactos de su equipo', 'Ve los contactos asignados a cualquier miembro de su equipo.', false, 'ver los de su equipo'],
        'contacts.view.own' => ['contacts', 'ver', 'Ver solo sus contactos', 'Ve únicamente los contactos asignados a la persona.', false, 'ver solo los suyos'],
        'contacts.create' => ['contacts', 'editar', 'Crear contactos', 'Registra contactos nuevos.', false, 'crear'],
        'contacts.update' => ['contacts', 'editar', 'Editar contactos', 'Modifica los datos de los contactos que puede ver.', false, 'editar'],
        'contacts.deactivate' => ['contacts', 'editar', 'Desactivar contactos', 'Da de baja contactos sin borrarlos.', false, 'desactivar'],
        'contacts.export' => ['contacts', 'especiales', 'Exportar contactos', 'Descarga el listado de contactos a un archivo.', true, 'exportar'],

        // Oportunidades
        'opportunities.view.any' => ['opportunities', 'ver', 'Ver todas las oportunidades', 'Ve las oportunidades de toda la empresa.', false, 'ver todas'],
        'opportunities.view.team' => ['opportunities', 'ver', 'Ver las oportunidades de su equipo', 'Ve las oportunidades asignadas a cualquier miembro de su equipo.', false, 'ver las de su equipo'],
        'opportunities.view.own' => ['opportunities', 'ver', 'Ver solo sus oportunidades', 'Ve únicamente las oportunidades asignadas a la persona.', false, 'ver solo las suyas'],
        'opportunities.create' => ['opportunities', 'editar', 'Crear oportunidades', 'Registra oportunidades de venta nuevas.', false, 'crear'],
        'opportunities.update' => ['opportunities', 'editar', 'Editar oportunidades', 'Modifica las oportunidades que puede ver.', false, 'editar'],
        'opportunities.deactivate' => ['opportunities', 'editar', 'Desactivar oportunidades', 'Da de baja oportunidades sin borrarlas.', false, 'desactivar'],
        'opportunities.win' => ['opportunities', 'especiales', 'Marcar oportunidades como ganadas', 'Cierra una oportunidad como venta ganada.', false, 'marcar como ganadas'],
        'opportunities.lose' => ['opportunities', 'especiales', 'Marcar oportunidades como perdidas', 'Cierra una oportunidad como venta perdida.', false, 'marcar como perdidas'],
        'opportunities.export' => ['opportunities', 'especiales', 'Exportar oportunidades', 'Descarga el listado de oportunidades a un archivo.', true, 'exportar'],

        // Actividades
        'activities.view.any' => ['activities', 'ver', 'Ver todas las actividades', 'Ve las actividades de toda la empresa.', false, 'ver todas'],
        'activities.view.team' => ['activities', 'ver', 'Ver las actividades de su equipo', 'Ve las actividades de cualquier miembro de su equipo.', false, 'ver las de su equipo'],
        'activities.view.own' => ['activities', 'ver', 'Ver solo sus actividades', 'Ve únicamente las actividades asignadas a la persona.', false, 'ver solo las suyas'],
        'activities.create' => ['activities', 'editar', 'Crear actividades', 'Programa llamadas, reuniones y tareas.', false, 'crear'],
        'activities.update' => ['activities', 'editar', 'Editar actividades', 'Modifica las actividades que puede ver.', false, 'editar'],
        'activities.deactivate' => ['activities', 'editar', 'Desactivar actividades', 'Da de baja actividades sin borrarlas.', false, 'desactivar'],
        'activities.complete' => ['activities', 'especiales', 'Completar actividades', 'Marca una actividad como realizada.', false, 'completar'],
        'activities.delete' => ['activities', 'especiales', 'Eliminar actividades', 'Borra actividades de forma definitiva.', true, 'eliminar'],
        'activities.export' => ['activities', 'especiales', 'Exportar actividades', 'Descarga el listado de actividades a un archivo.', true, 'exportar'],

        // Campañas
        'campaigns.view' => ['campaigns', 'ver', 'Ver campañas', 'Consulta las campañas y su avance.', false, 'ver'],
        'campaigns.view_reports' => ['campaigns', 'ver', 'Ver reportes de campañas', 'Consulta los resultados de las campañas.', false, 'ver reportes'],
        'campaigns.create' => ['campaigns', 'editar', 'Crear campañas', 'Prepara campañas nuevas.', false, 'crear'],
        'campaigns.update' => ['campaigns', 'editar', 'Editar campañas', 'Modifica los datos de una campaña.', false, 'editar'],
        'campaigns.duplicate' => ['campaigns', 'editar', 'Duplicar campañas', 'Crea una campaña nueva a partir de otra.', false, 'duplicar'],
        'campaigns.add_contacts' => ['campaigns', 'editar', 'Agregar contactos a campañas', 'Suma destinatarios a una campaña.', false, 'agregar contactos'],
        'campaigns.remove_contacts' => ['campaigns', 'editar', 'Quitar contactos de campañas', 'Retira destinatarios de una campaña.', false, 'quitar contactos'],
        'campaigns.schedule' => ['campaigns', 'especiales', 'Programar campañas', 'Define cuándo se ejecuta una campaña.', false, 'programar'],
        'campaigns.start' => ['campaigns', 'especiales', 'Iniciar campañas', 'Pone en marcha una campaña.', false, 'iniciar'],
        'campaigns.pause' => ['campaigns', 'especiales', 'Pausar campañas', 'Detiene temporalmente una campaña en curso.', false, 'pausar'],
        'campaigns.complete' => ['campaigns', 'especiales', 'Finalizar campañas', 'Da por terminada una campaña.', false, 'finalizar'],
        'campaigns.cancel' => ['campaigns', 'especiales', 'Cancelar campañas', 'Anula una campaña antes de terminarla.', false, 'cancelar'],
        'campaigns.register_actions' => ['campaigns', 'especiales', 'Registrar acciones de campaña', 'Anota las gestiones realizadas con cada contacto.', false, 'registrar acciones'],
        'campaigns.reschedule' => ['campaigns', 'especiales', 'Reprogramar acciones de campaña', 'Cambia la fecha de una acción pendiente.', false, 'reprogramar acciones'],
        'campaigns.mark_realized' => ['campaigns', 'especiales', 'Marcar acciones como realizadas', 'Confirma que una acción de campaña se hizo.', false, 'marcar acciones realizadas'],
        'campaigns.override_completion' => ['campaigns', 'especiales', 'Corregir acciones ya cerradas', 'Modifica acciones de campaña aunque ya estén completadas o sean de otra persona.', true, 'corregir acciones cerradas'],
        'campaign_templates.view' => ['campaigns', 'ver', 'Ver plantillas de campaña', 'Consulta las plantillas disponibles.', false, 'ver plantillas'],
        'campaign_templates.create' => ['campaigns', 'editar', 'Crear plantillas de campaña', 'Diseña plantillas nuevas para campañas.', false, 'crear plantillas'],
        'campaign_templates.update' => ['campaigns', 'editar', 'Editar plantillas de campaña', 'Modifica plantillas existentes.', false, 'editar plantillas'],
        'campaign_templates.duplicate' => ['campaigns', 'editar', 'Duplicar plantillas de campaña', 'Copia una plantilla para reutilizarla.', false, 'duplicar plantillas'],

        // Calendario
        'calendar.view' => ['calendar', 'ver', 'Ver el calendario', 'Consulta el calendario de actividades y eventos.', false, 'ver'],

        // Cursos y charlas
        'course-talks.view' => ['course-talks', 'ver', 'Ver cursos y charlas', 'Consulta cursos, charlas y sus ediciones.', false, 'ver'],
        'course-talks.activities.manage' => ['course-talks', 'editar', 'Gestionar cursos y charlas', 'Crea y modifica cursos y charlas.', false, 'gestionar cursos'],
        'course-talks.editions.manage' => ['course-talks', 'editar', 'Gestionar ediciones', 'Crea y modifica las ediciones de cada curso.', false, 'gestionar ediciones'],
        'course-talks.sessions.manage' => ['course-talks', 'editar', 'Gestionar sesiones', 'Programa y modifica las sesiones de una edición.', false, 'gestionar sesiones'],
        'course-talks.participants.manage' => ['course-talks', 'editar', 'Gestionar participantes', 'Inscribe y administra a los participantes.', false, 'gestionar participantes'],
        'course-talks.attendance.manage' => ['course-talks', 'editar', 'Registrar asistencia', 'Marca la asistencia de los participantes.', false, 'registrar asistencia'],
        'course-talks.grades.manage' => ['course-talks', 'editar', 'Registrar notas', 'Ingresa y corrige las notas de los participantes.', false, 'registrar notas'],
        'course-talks.commercial-documents.manage' => ['course-talks', 'editar', 'Gestionar documentos comerciales', 'Prepara y sube brochures, propuestas y similares.', false, 'gestionar documentos comerciales'],
        'course-talks.templates.manage' => ['course-talks', 'editar', 'Gestionar plantillas de certificados', 'Diseña las plantillas de certificados y constancias.', false, 'gestionar plantillas'],
        'course-talks.documents.generate' => ['course-talks', 'especiales', 'Emitir certificados', 'Genera certificados y constancias para los participantes.', false, 'emitir certificados'],
        'course-talks.documents.send' => ['course-talks', 'especiales', 'Enviar documentos', 'Envía certificados y documentos a los participantes.', false, 'enviar documentos'],
        'course-talks.documents.revoke' => ['course-talks', 'especiales', 'Anular certificados', 'Revoca certificados ya emitidos.', true, 'anular certificados'],
        'course-talks.audit.view' => ['course-talks', 'administracion', 'Ver historial de cursos', 'Consulta quién hizo qué en cursos y charlas.', true, 'ver historial'],

        // Soporte
        'support.view.any' => ['support', 'ver', 'Ver todos los tickets', 'Ve los tickets de soporte de toda la empresa.', false, 'ver todos'],
        'support.view.team' => ['support', 'ver', 'Ver los tickets de su equipo', 'Ve los tickets de cualquier miembro de su equipo.', false, 'ver los de su equipo'],
        'support.view.own' => ['support', 'ver', 'Ver solo sus tickets', 'Ve únicamente los tickets asignados a la persona.', false, 'ver solo los suyos'],
        'support.reports.view' => ['support', 'ver', 'Ver reportes de soporte', 'Consulta indicadores y reportes de soporte.', false, 'ver reportes'],
        'support.create' => ['support', 'editar', 'Crear tickets', 'Registra tickets de soporte nuevos.', false, 'crear'],
        'support.update' => ['support', 'editar', 'Editar tickets', 'Modifica los datos de los tickets que puede ver.', false, 'editar'],
        'support.updates.create' => ['support', 'editar', 'Registrar avances', 'Agrega notas de avance a un ticket.', false, 'registrar avances'],
        'support.priority.update' => ['support', 'editar', 'Cambiar prioridad', 'Sube o baja la prioridad de un ticket.', false, 'cambiar prioridad'],
        'support.assign' => ['support', 'especiales', 'Asignar tickets', 'Asigna un responsable a un ticket.', false, 'asignar'],
        'support.reassign' => ['support', 'especiales', 'Reasignar tickets', 'Cambia el responsable de un ticket ya asignado.', false, 'reasignar'],
        'support.schedule' => ['support', 'especiales', 'Programar atención', 'Agenda la fecha de atención de un ticket.', false, 'programar'],
        'support.reschedule' => ['support', 'especiales', 'Reprogramar atención', 'Cambia la fecha de atención agendada.', false, 'reprogramar'],
        'support.attention.start' => ['support', 'especiales', 'Iniciar atención', 'Marca que se empezó a atender un ticket.', false, 'iniciar atención'],
        'support.observations.create' => ['support', 'especiales', 'Registrar observaciones', 'Anota observaciones pendientes en un ticket.', false, 'registrar observaciones'],
        'support.observations.lift' => ['support', 'especiales', 'Levantar observaciones', 'Indica que una observación fue atendida.', false, 'levantar observaciones'],
        'support.observations.validate' => ['support', 'especiales', 'Validar observaciones', 'Aprueba que una observación quedó resuelta.', false, 'validar observaciones'],
        'support.observations.reject' => ['support', 'especiales', 'Rechazar observaciones', 'Devuelve una observación que no quedó resuelta.', false, 'rechazar observaciones'],
        'support.resolve' => ['support', 'especiales', 'Resolver tickets', 'Marca un ticket como resuelto.', false, 'resolver'],
        'support.close' => ['support', 'especiales', 'Cerrar tickets', 'Cierra un ticket resuelto.', false, 'cerrar'],
        'support.close.with-pending-observations' => ['support', 'especiales', 'Cerrar tickets con observaciones pendientes', 'Cierra un ticket aunque tenga observaciones sin resolver.', true, 'cerrar con observaciones pendientes'],
        'support.reopen' => ['support', 'especiales', 'Reabrir tickets', 'Vuelve a abrir un ticket cerrado.', false, 'reabrir'],
        'support.cancel' => ['support', 'especiales', 'Anular tickets', 'Cancela un ticket que no procede.', false, 'anular'],
        'support.catalogs.manage' => ['support', 'administracion', 'Gestionar catálogos de soporte', 'Administra tipos, categorías y demás listas de soporte.', false, 'gestionar catálogos'],

        // Cronograma de contenidos
        'content_projects.view' => ['content_projects', 'ver', 'Ver proyectos de contenido', 'Consulta los proyectos de contenido en los que participa.', false, 'ver los suyos'],
        'content_projects.view_all' => ['content_projects', 'ver', 'Ver todos los proyectos de contenido', 'Consulta cualquier proyecto de contenido, aunque no participe en él.', false, 'ver todos'],
        'content_projects.create' => ['content_projects', 'editar', 'Crear proyectos de contenido', 'Crea proyectos y cronogramas de contenido nuevos.', false, 'crear'],
        'content_projects.update' => ['content_projects', 'editar', 'Editar proyectos de contenido', 'Modifica proyectos y sus publicaciones.', false, 'editar'],
        'content_projects.assign_members' => ['content_projects', 'especiales', 'Asignar miembros', 'Define quién participa en cada proyecto de contenido.', false, 'asignar miembros'],
        'content_projects.archive' => ['content_projects', 'especiales', 'Archivar proyectos de contenido', 'Guarda como archivados los proyectos terminados.', false, 'archivar'],

        // Productos
        'products.view.any' => ['products', 'ver', 'Ver todos los productos', 'Consulta el catálogo completo de productos.', false, 'ver todos'],
        'products.view.team' => ['products', 'ver', 'Ver los productos de su equipo', 'Consulta los productos asociados a su equipo.', false, 'ver los de su equipo'],
        'products.view.own' => ['products', 'ver', 'Ver solo sus productos', 'Consulta únicamente los productos asociados a la persona.', false, 'ver solo los suyos'],
        'products.create' => ['products', 'editar', 'Crear productos', 'Agrega productos y servicios al catálogo.', false, 'crear'],
        'products.update' => ['products', 'editar', 'Editar productos', 'Modifica precios y datos de los productos.', false, 'editar'],
        'products.deactivate' => ['products', 'editar', 'Desactivar productos', 'Retira productos del catálogo sin borrarlos.', false, 'desactivar'],
        'products.export' => ['products', 'especiales', 'Exportar productos', 'Descarga el catálogo de productos a un archivo.', true, 'exportar'],
        'products.import' => ['products', 'especiales', 'Importar productos', 'Carga productos masivamente desde un archivo.', true, 'importar'],

        // Cotizaciones
        'quotations.view.any' => ['quotations', 'ver', 'Ver todas las cotizaciones', 'Ve las cotizaciones de toda la empresa.', false, 'ver todas'],
        'quotations.view.team' => ['quotations', 'ver', 'Ver las cotizaciones de su equipo', 'Ve las cotizaciones de cualquier miembro de su equipo.', false, 'ver las de su equipo'],
        'quotations.view.own' => ['quotations', 'ver', 'Ver solo sus cotizaciones', 'Ve únicamente las cotizaciones de la persona.', false, 'ver solo las suyas'],
        'quotations.create' => ['quotations', 'editar', 'Crear cotizaciones', 'Prepara cotizaciones nuevas.', false, 'crear'],
        'quotations.update' => ['quotations', 'editar', 'Editar cotizaciones', 'Modifica las cotizaciones que puede ver.', false, 'editar'],
        'quotations.duplicate' => ['quotations', 'editar', 'Duplicar cotizaciones', 'Crea una cotización nueva a partir de otra.', false, 'duplicar'],
        'quotations.deactivate' => ['quotations', 'editar', 'Desactivar cotizaciones', 'Da de baja cotizaciones sin borrarlas.', false, 'desactivar'],
        'quotations.accept' => ['quotations', 'especiales', 'Marcar cotizaciones como aceptadas', 'Registra que el cliente aceptó la cotización.', false, 'marcar como aceptadas'],
        'quotations.reject' => ['quotations', 'especiales', 'Marcar cotizaciones como rechazadas', 'Registra que el cliente rechazó la cotización.', false, 'marcar como rechazadas'],
        'quotations.delete' => ['quotations', 'especiales', 'Eliminar cotizaciones', 'Borra cotizaciones de forma definitiva.', true, 'eliminar'],
        'quotations.export' => ['quotations', 'especiales', 'Exportar cotizaciones', 'Descarga el listado de cotizaciones a un archivo.', true, 'exportar'],

        // Documentos
        'documents.view.any' => ['documents', 'ver', 'Ver todos los documentos', 'Ve los documentos de toda la empresa.', false, 'ver todos'],
        'documents.view.team' => ['documents', 'ver', 'Ver los documentos de su equipo', 'Ve los documentos de cualquier miembro de su equipo.', false, 'ver los de su equipo'],
        'documents.view.own' => ['documents', 'ver', 'Ver solo sus documentos', 'Ve únicamente los documentos que subió la persona.', false, 'ver solo los suyos'],
        'documents.download' => ['documents', 'ver', 'Descargar documentos', 'Descarga los archivos que puede ver.', false, 'descargar'],
        'documents.upload' => ['documents', 'editar', 'Subir documentos', 'Adjunta archivos a prospectos, clientes y otros registros.', false, 'subir'],
        'documents.delete' => ['documents', 'especiales', 'Eliminar documentos', 'Borra archivos adjuntos.', true, 'eliminar'],

        // Reportes
        'reports.view' => ['reports', 'ver', 'Ver reportes', 'Consulta el tablero y los reportes comerciales.', false, 'ver'],

        // Correo electrónico
        'email.view' => ['email', 'ver', 'Ver correos', 'Consulta los correos enviados desde el CRM.', false, 'ver'],
        'email.send' => ['email', 'especiales', 'Enviar correos', 'Envía correos desde el CRM con su propia cuenta.', false, 'enviar'],
        'email.shared.use' => ['email', 'especiales', 'Usar la cuenta de correo compartida', 'Envía correos desde la cuenta común de la empresa.', false, 'usar cuenta compartida'],
        'email.template.manage' => ['email', 'administracion', 'Gestionar plantillas de correo', 'Crea y modifica plantillas de correo.', false, 'gestionar plantillas'],
        'email.account.manage' => ['email', 'administracion', 'Gestionar cuentas de correo', 'Conecta y configura las cuentas de correo de la empresa.', true, 'gestionar cuentas'],
        'email.audit' => ['email', 'administracion', 'Ver historial de correos', 'Consulta el registro de auditoría de los envíos.', true, 'ver historial'],

        // WhatsApp
        'whatsapp.view' => ['whatsapp', 'ver', 'Ver conversaciones de WhatsApp', 'Consulta las conversaciones de WhatsApp.', false, 'ver'],
        'whatsapp.send' => ['whatsapp', 'especiales', 'Enviar mensajes de WhatsApp', 'Envía mensajes desde el CRM.', false, 'enviar'],
        'whatsapp.conversation.assign' => ['whatsapp', 'especiales', 'Asignar conversaciones', 'Asigna una conversación a otra persona.', false, 'asignar conversaciones'],
        'whatsapp.template.manage' => ['whatsapp', 'administracion', 'Gestionar plantillas de WhatsApp', 'Crea y modifica plantillas de mensajes.', false, 'gestionar plantillas'],
        'whatsapp.account.manage' => ['whatsapp', 'administracion', 'Gestionar cuentas de WhatsApp', 'Conecta y configura los números de WhatsApp.', true, 'gestionar cuentas'],
        'whatsapp.audit' => ['whatsapp', 'administracion', 'Ver historial de WhatsApp', 'Consulta el registro de auditoría de WhatsApp.', true, 'ver historial'],

        // Notificaciones
        'notifications.view' => ['notifications', 'ver', 'Ver notificaciones enviadas', 'Consulta las notificaciones que envió el sistema.', false, 'ver'],
        'notifications.send' => ['notifications', 'especiales', 'Enviar notificaciones', 'Envía notificaciones manuales a los usuarios.', false, 'enviar'],
        'notifications.manage' => ['notifications', 'administracion', 'Configurar notificaciones', 'Define qué notificaciones se envían y cómo.', false, 'configurar'],
        'notifications.audit' => ['notifications', 'administracion', 'Ver historial de notificaciones', 'Consulta el registro de auditoría de notificaciones.', true, 'ver historial'],

        // Consentimientos
        'consent.view' => ['consent', 'ver', 'Ver consentimientos', 'Consulta los consentimientos y la lista de exclusión.', false, 'ver'],
        'consent.grant' => ['consent', 'especiales', 'Registrar consentimientos', 'Anota que un contacto aceptó recibir comunicaciones.', false, 'registrar'],
        'consent.revoke' => ['consent', 'especiales', 'Revocar consentimientos', 'Anota que un contacto ya no desea comunicaciones.', false, 'revocar'],
        'consent.audit' => ['consent', 'administracion', 'Ver historial de consentimientos', 'Consulta el registro de auditoría completo.', true, 'ver historial'],

        // Automatizaciones
        'automations.view' => ['automations', 'ver', 'Ver automatizaciones', 'Consulta las reglas automáticas configuradas.', false, 'ver'],
        'automations.manage' => ['automations', 'editar', 'Gestionar automatizaciones', 'Crea, modifica y activa reglas automáticas.', false, 'gestionar'],
        'automations.test' => ['automations', 'especiales', 'Probar automatizaciones', 'Simula una regla sin afectar datos reales.', false, 'probar'],
        'automations.webhook.execute' => ['automations', 'especiales', 'Ejecutar webhooks', 'Dispara llamadas a sistemas externos desde una regla.', true, 'ejecutar webhooks'],
        'automations.audit' => ['automations', 'administracion', 'Ver historial de automatizaciones', 'Consulta las ejecuciones registradas.', true, 'ver historial'],

        // Usuarios
        'users.view' => ['users', 'ver', 'Ver usuarios', 'Consulta la lista de usuarios del sistema.', false, 'ver'],
        'users.create' => ['users', 'administracion', 'Crear usuarios', 'Da de alta usuarios nuevos.', true, 'crear'],
        'users.update' => ['users', 'administracion', 'Editar usuarios', 'Modifica los datos de los usuarios.', true, 'editar'],
        'users.deactivate' => ['users', 'administracion', 'Desactivar usuarios', 'Bloquea el acceso de un usuario sin borrarlo.', true, 'desactivar'],
        'users.reset_password' => ['users', 'administracion', 'Restablecer contraseñas', 'Genera una contraseña nueva para otro usuario.', true, 'restablecer contraseñas'],
        'users.assign_role' => ['users', 'administracion', 'Asignar roles a usuarios', 'Cambia el rol, y por tanto los permisos, de un usuario.', true, 'asignar roles'],
        'users.manage' => ['users', 'administracion', 'Gestionar usuarios', 'Permiso general de administración de usuarios.', true, 'gestionar'],

        // Equipos
        'teams.view' => ['teams', 'ver', 'Ver equipos', 'Consulta los equipos y sus miembros.', false, 'ver'],
        'teams.manage' => ['teams', 'administracion', 'Gestionar equipos', 'Crea equipos y define sus miembros y supervisores.', false, 'gestionar'],

        // Roles
        'roles.view' => ['roles', 'ver', 'Ver roles', 'Consulta los roles y lo que permite cada uno.', false, 'ver'],
        'roles.manage' => ['roles', 'administracion', 'Gestionar roles y permisos', 'Crea roles y cambia sus permisos.', true, 'gestionar'],

        // Catálogos
        'catalogs.view' => ['catalogs', 'ver', 'Ver catálogos', 'Consulta las listas de valores (fuentes, monedas, etc.).', false, 'ver'],
        'catalogs.manage' => ['catalogs', 'administracion', 'Gestionar catálogos', 'Agrega y modifica las listas de valores.', false, 'gestionar'],

        // Configuración
        'settings.view' => ['settings', 'ver', 'Ver configuración', 'Consulta la configuración general del sistema.', false, 'ver'],
        'settings.manage' => ['settings', 'administracion', 'Cambiar configuración', 'Modifica la configuración general del sistema.', true, 'cambiar'],

        // Auditoría
        'audit.view' => ['audit', 'administracion', 'Ver auditoría', 'Consulta el registro de quién hizo qué en el sistema.', true, 'ver'],

        // Datos de demostración
        'demo-data.manage' => ['demo-data', 'administracion', 'Gestionar datos de demostración', 'Genera o borra datos ficticios de prueba.', true, 'gestionar'],
    ];

    /** Spanish words for the fallback label of unknown permissions. */
    private const FALLBACK_WORDS = [
        'view' => 'ver', 'create' => 'crear', 'update' => 'editar', 'delete' => 'eliminar',
        'manage' => 'gestionar', 'export' => 'exportar', 'import' => 'importar', 'send' => 'enviar',
        'audit' => 'auditar', 'assign' => 'asignar', 'deactivate' => 'desactivar', 'archive' => 'archivar',
        'approve' => 'aprobar', 'download' => 'descargar', 'upload' => 'subir',
        'any' => 'todos', 'team' => 'del equipo', 'own' => 'propios', 'all' => 'todo',
    ];

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::ENTRIES);
    }

    /**
     * @return array{key:string, name:string, icon:string, order:int}
     */
    public static function module(string $name): array
    {
        return self::moduleInfo(self::ENTRIES[$name][0] ?? 'otros');
    }

    /**
     * @return array{key:string, name:string, icon:string, order:int}
     */
    public static function moduleInfo(string $key): array
    {
        [$label, $icon, $order] = self::MODULES[$key] ?? self::MODULES['otros'];

        return ['key' => isset(self::MODULES[$key]) ? $key : 'otros', 'name' => $label, 'icon' => $icon, 'order' => $order];
    }

    public static function label(string $name): string
    {
        return self::ENTRIES[$name][2] ?? self::fallbackLabel($name);
    }

    public static function describe(string $name): string
    {
        return self::ENTRIES[$name][3] ?? 'Permiso adicional sin descripción en el catálogo.';
    }

    public static function group(string $name): string
    {
        return self::ENTRIES[$name][1] ?? 'especiales';
    }

    public static function isSensitive(string $name): bool
    {
        return self::ENTRIES[$name][4] ?? false;
    }

    public static function isFullAccess(Role $role): bool
    {
        return $role->name === self::FULL_ACCESS_ROLE;
    }

    /**
     * Read-scope triplets: prefix => ['own' => name, 'team' => name, 'any' => name],
     * only when all three permissions exist.
     *
     * @param  iterable<string>  $names
     * @return array<string, array{own:string, team:string, any:string}>
     */
    public static function scopeGroups(iterable $names): array
    {
        $found = [];
        foreach ($names as $name) {
            if (preg_match('/^(.+)\.view\.(own|team|any)$/', $name, $m)) {
                $found[$m[1]][$m[2]] = $name;
            }
        }

        $groups = [];
        foreach ($found as $prefix => $scopes) {
            if (count($scopes) === 3) {
                $groups[$prefix] = ['own' => $scopes['own'], 'team' => $scopes['team'], 'any' => $scopes['any']];
            }
        }

        return $groups;
    }

    /**
     * Widest scope held per triplet (any > team > own); "none" when no scope is held.
     *
     * @param  iterable<string>  $selected
     * @param  array<string, array{own:string, team:string, any:string}>  $scopeGroups
     * @return array<string, string>
     */
    public static function selectedScopes(iterable $selected, array $scopeGroups): array
    {
        $selected = collect($selected)->flip();
        $result = [];

        foreach ($scopeGroups as $prefix => $triplet) {
            $result[$prefix] = 'none';
            foreach (array_reverse(self::SCOPES) as $scope) {
                if ($selected->has($triplet[$scope])) {
                    $result[$prefix] = $scope;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Permissions arranged for the role form: modules in sidebar order, each
     * with its optional read-scope triplet and the remaining actions by group.
     *
     * @param  iterable<string>  $names
     * `scopeKey` is the triplet prefix used as the form field `scope[<scopeKey>]`.
     *
     * @return list<array{key:string, name:string, icon:string, order:int, scope:array<string,string>|null, scopeKey:string|null, groups:list<array{key:string, label:string, items:list<array{name:string, label:string, description:string, sensitive:bool}>}>}>
     */
    public static function grouped(iterable $names): array
    {
        $names = collect($names)->values();
        $scopeGroups = self::scopeGroups($names);
        $scoped = collect($scopeGroups)->flatten()->flip();

        $modules = [];
        foreach ($names as $name) {
            $module = self::module($name);
            $modules[$module['key']] ??= $module + ['scope' => null, 'scopeKey' => null, 'items' => []];

            if (! $scoped->has($name)) {
                $modules[$module['key']]['items'][] = $name;
            }
        }

        foreach ($scopeGroups as $prefix => $triplet) {
            $key = self::module($triplet['any'])['key'];
            $modules[$key]['scope'] = $triplet;
            $modules[$key]['scopeKey'] = $prefix;
        }

        $order = array_flip(array_keys(self::ENTRIES));
        $groupOrder = array_flip(array_keys(self::GROUPS));

        return collect($modules)
            ->sortBy('order')
            ->map(function (array $module) use ($order, $groupOrder) {
                $module['groups'] = collect($module['items'])
                    ->sortBy(fn (string $name) => [$order[$name] ?? PHP_INT_MAX, $name])
                    ->groupBy(fn (string $name) => self::group($name))
                    ->sortBy(fn ($items, string $group) => $groupOrder[$group])
                    ->map(fn ($items, string $group) => [
                        'key' => $group,
                        'label' => self::GROUPS[$group],
                        'items' => $items->map(fn (string $name) => [
                            'name' => $name,
                            'label' => self::label($name),
                            'description' => self::describe($name),
                            'sensitive' => self::isSensitive($name),
                        ])->values()->all(),
                    ])
                    ->values()
                    ->all();
                unset($module['items']);

                return $module;
            })
            ->values()
            ->all();
    }

    /**
     * Readable per-module summary of what a role (or a list of permission
     * names) allows, e.g. "Prospectos: ver los de su equipo, crear, editar".
     * When a role holds several read scopes of one module only the widest is
     * reported, mirroring what the role form preselects.
     *
     * @param  Role|iterable<string>  $role
     * @return list<array{key:string, name:string, icon:string, actions:string, text:string}>
     */
    public static function summaryFor(Role|iterable $role): array
    {
        $names = collect($role instanceof Role ? $role->permissions->pluck('name') : $role)->values();
        $scopeGroups = self::scopeGroups($names);
        $widest = self::selectedScopes($names, $scopeGroups);
        $order = array_flip(array_keys(self::ENTRIES));

        $hidden = [];
        foreach ($scopeGroups as $prefix => $triplet) {
            foreach ($triplet as $scope => $name) {
                if ($scope !== $widest[$prefix]) {
                    $hidden[$name] = true;
                }
            }
        }

        return $names
            ->reject(fn (string $name) => isset($hidden[$name]))
            ->sortBy(fn (string $name) => [$order[$name] ?? PHP_INT_MAX, $name])
            ->groupBy(fn (string $name) => self::module($name)['key'])
            ->map(function ($items, string $key) {
                $module = self::moduleInfo($key);
                $actions = $items->map(fn (string $name) => self::ENTRIES[$name][5] ?? Str::lower(self::label($name)))
                    ->unique()
                    ->implode(', ');

                return [
                    'key' => $module['key'],
                    'name' => $module['name'],
                    'icon' => $module['icon'],
                    'order' => $module['order'],
                    'actions' => $actions,
                    'text' => $module['name'].': '.$actions,
                ];
            })
            ->sortBy('order')
            ->map(fn (array $item) => collect($item)->except('order')->all())
            ->values()
            ->all();
    }

    private static function fallbackLabel(string $name): string
    {
        $parts = preg_split('/[.]/', $name) ?: [$name];
        $resource = str_replace(['_', '-'], ' ', (string) array_shift($parts));

        $words = collect($parts)
            ->flatMap(fn (string $part) => preg_split('/[_-]/', $part) ?: [])
            ->filter()
            ->map(fn (string $word) => self::FALLBACK_WORDS[$word] ?? $word)
            ->implode(' ');

        return $words === ''
            ? Str::ucfirst($resource)
            : Str::ucfirst($words).' ('.$resource.')';
    }
}
