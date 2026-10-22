# Guía de usuario — Roles y permisos

Esta guía explica cómo decidir **qué puede ver y hacer** cada persona en el CRM. Está pensada para
administradores sin conocimientos técnicos.

---

## 1. Conceptos

- Un **rol** es un perfil de trabajo (por ejemplo *vendedor*, *supervisor*, *contabilidad*). Cada usuario
  tiene un rol.
- Un **permiso** es una acción concreta que el rol puede realizar: "Crear prospectos", "Exportar
  clientes", "Ver todos los tickets de soporte"…
- Cambiar los permisos de un rol afecta **a todas las personas que tienen ese rol**.

## 2. Dónde está

Menú lateral, sección **Administración → Roles** (requiere el permiso *Administrar roles*). La página se titula **Roles y permisos**.

## 3. La lista de roles

Cada fila muestra:

- **Rol**, con la marca **Del sistema** para *admin*, *supervisor* y *vendedor* (vienen con el CRM y no se
  pueden eliminar ni renombrar).
- **Acceso a módulos**: los módulos donde el rol tiene algún permiso (por ejemplo *Prospectos,
  Clientes, Cotizaciones…*). El administrador aparece como **Acceso total**.
- Cantidad de **usuarios** y de **permisos**, y los botones **Ver** y **Editar**.

## 4. Crear o editar un rol

1. Pulse **Nuevo rol** (o **Editar** en un rol existente).
2. Escriba el **Nombre del rol**: corto y en minúsculas, por ejemplo *coordinador*. Se muestra con
   mayúscula inicial.
3. Al crear, puede usar **Copiar permisos de otro rol** para partir de uno parecido y ajustar solo las
   diferencias.
4. Configure los permisos por módulo (ver sección 5).
5. Pulse **Guardar cambios**. La barra inferior muestra cuántos permisos tiene seleccionados.

## 5. Los permisos, módulo por módulo

Los permisos están agrupados por **módulo**, con los mismos nombres e íconos del menú lateral. Cada
módulo muestra cuántos permisos tiene activos (por ejemplo **5 de 8**) y los botones **Marcar todo** /
**Quitar todo**. Use **Expandir todo** / **Contraer todo** para abrirlos o cerrarlos.

### ¿Qué registros puede ver?

En los módulos con registros propios (prospectos, clientes, oportunidades, actividades, cotizaciones,
soporte, etc.) se elige **una sola opción**:

| Opción | Qué ve la persona |
|---|---|
| **Sin acceso** | No ve el módulo. |
| **Solo los suyos** | Solo los registros donde ella es la responsable. |
| **Los de su equipo** | Los suyos y los de los miembros de los equipos que supervisa. |
| **Todos** | Todos los registros de la empresa. |

«Todos» incluye los de su equipo, y estos incluyen los suyos.

### Acciones

Debajo están las acciones, agrupadas en **Crear y editar**, **Acciones especiales** y
**Administración**. Cada una tiene un interruptor, un nombre claro y una descripción corta.

Las marcadas como **Sensible** permiten borrar, exportar, importar o administrar información
importante. Actívelas solo para quien realmente las necesite.

### Buscar

El buscador filtra los permisos por texto: escriba *exportar*, *clientes* o *contraseñas* para ver
solo los permisos relacionados, en todos los módulos.

## 6. El rol administrador

El **admin** tiene **acceso total**: puede hacer todo, siempre, sin importar los permisos marcados. Por
eso su pantalla no muestra la lista de permisos.

## 7. Asignar un rol a un usuario

En **Usuarios → Nuevo / Editar**, elija el **Rol**. Debajo del selector aparece un resumen de los
módulos a los que ese rol da acceso, para confirmar que es el adecuado.

## 8. Preguntas frecuentes

**¿Por qué no puedo cambiar el nombre de *vendedor*?**
Los roles del sistema se reconocen por su nombre; cambiarlo rompería sus permisos. Cree un rol nuevo
si necesita otro nombre.

**Guardé un rol sin tocar nada, ¿cambió algo?**
No. Guardar sin cambios conserva exactamente los mismos permisos.

**Un rol tenía marcadas varias opciones de lectura (por ejemplo "suyos" y "todos").**
La pantalla muestra la más amplia (**Todos**) y, al guardar, queda solo esa, que ya incluye a las
demás.

**Quité un permiso y la persona todavía ve la pantalla.**
Pídale que vuelva a iniciar sesión o recargue la página.
