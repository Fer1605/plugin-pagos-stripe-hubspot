# HubSpot Elementor Form

Envía las respuestas de **Elementor Forms** a **HubSpot** usando la *Forms Submissions API v3*, solo cuando el formulario pasa todas las validaciones.

**Autor:** [Jonathan Diaz](https://www.soydiaz.com) · Versión 1.4.0 · Licencia GPL-2.0-or-later

---

## Qué hace

| Requisito | Implementación |
|---|---|
| Campo añadible al formulario | Tipo de campo **"HubSpot (configuración)"** registrado en el widget Formulario |
| Portal ID / Form ID una sola vez | Se configuran en ese único campo |
| Mapeo campo → keyid de HubSpot | Grupo **"Mapeo de campos"** (Campo del sitio → Propiedad) dentro del campo de HubSpot |
| Enviar solo si el envío es válido | Se engancha a `elementor_pro/forms/new_record`, que Elementor dispara *después* de todas las validaciones (requeridos, reCAPTCHA, honeypot…) |
| Activar el envío | Acción **HubSpot** en *Acciones después de enviar*: único interruptor, y el resultado queda en el Actions Log |
| API más reciente | `POST https://api.hsforms.com/submissions/v3/integration/submit/{portalId}/{formGuid}` (y variante `/secure/` con token) |
| Funciona con CAPTCHA activo en HubSpot | El endpoint v3 es servidor-a-servidor; el CAPTCHA solo aplica al formulario embebido de HubSpot |
| Log de datos enviados y respuesta | Tabla `{prefix}hef_logs` + pantalla **HubSpot Forms → Registros** |
| Reintento si algo falla | Botón *Reintentar* por fila, acción en lote, y cron automático con espera progresiva |
| Mapeo automático | Pega la URL del formulario de HubSpot y el plugin rellena Portal ID, Form ID, región y la propiedad más probable de cada campo |
| Sin campos al aire | Crea en Elementor los campos obligatorios de HubSpot que falten, ya mapeados |
| Cambios en HubSpot | Re-escaneo que detecta propiedades nuevas, eliminadas y las que pasaron a obligatorias |
| Distinguir el origen | Huella en el contexto del envío (URL + nombre de página), sin crear campos en HubSpot |

## Instalación

1. Copia la carpeta `hubspot_elementor_form` en `wp-content/plugins/`.
2. Actívalo en **Plugins**.
3. Requiere WordPress 5.6+, PHP 7.4+ y **Elementor Pro 3.5+**.

## Configuración

### 1. En el formulario de Elementor

Añade un campo del tipo **HubSpot (configuración)** (no se ve en el sitio público, solo en el editor).

Lo **primero** que pide el campo es la **URL del formulario de HubSpot**: el enlace de compartir (`https://share.hsforms.com/…`), la URL del editor (`app.hubspot.com/forms/…`), la de una página donde esté embebido, o `portalId/formId`. De ahí sale todo lo demás, y queda guardada como referencia cuando alguien edite el formulario más adelante.

Pulsa **Escanear / actualizar campos** y el plugin:

1. Extrae el **Portal ID**, el **Form ID** y la **región**, y los escribe en el campo.
2. Descarga la definición del formulario de HubSpot (definición pública del embed; si no está disponible, la Marketing Forms API v3 con tu token).
3. Rellena en cada campo de Elementor la **propiedad más probable**, con puntuación de confianza.
4. **Crea los campos que falten**: por cada propiedad de HubSpot sin equivalente crea el campo en Elementor con su tipo correcto (email, tel, textarea, select con sus opciones, fecha, subida de archivo…), su etiqueta y su obligatoriedad, y añade su línea al grupo. Se insertan justo antes del campo de configuración.
5. Muestra un informe en el panel y en una ventana: mapeados, creados, obligatoriedad sincronizada, mapeos respetados y avisos.

#### Volver a escanear cuando cambia el formulario en HubSpot

El mismo botón sirve de sincronización. Al pulsarlo de nuevo (siempre pide datos frescos, sin caché) detecta:

- **Propiedades nuevas** en HubSpot → crea el campo en Elementor según la opción elegida.
- **Propiedades que pasaron a obligatorias** → marca el campo como obligatorio en Elementor.
- **Propiedades eliminadas de HubSpot** que siguen mapeadas aquí → aviso destacado. Es crítico: HubSpot **rechaza el envío completo con `400`** si recibe una propiedad que ya no está en el formulario, así que hay que borrar ese mapeo o recrear la propiedad allí.

Opciones del escaneo:

- **Crear los campos que faltan** — *Solo las obligatorias* (por defecto), *Todas las propiedades del formulario*, o *No crear campos (solo avisar)*.
- **Sincronizar obligatoriedad** — activado por defecto.
- **Sobrescribir mapeos existentes** — desactivado por defecto: el escaneo solo rellena lo vacío y respeta lo que hayas puesto a mano.

Después siempre puedes revisar campo a campo: cambiar la propiedad, borrarla o añadir otras.

El resto de opciones del campo:

- **Región de la cuenta** — `na1` (por defecto), `na2`, `eu1`, `ap1`. Determina el host de envío (`api-eu1.hsforms.com` para Europa).
- **Usar endpoint seguro** — opcional, requiere token de app privada en Ajustes
- **Enviar campos vacíos** — por defecto los campos vacíos se omiten
- **Valores fijos adicionales** — una línea por propiedad: `propiedad|valor`
- **Consentimiento (RGPD)** — texto y IDs de tipos de suscripción

### 2. El grupo "Mapeo de campos"

Todo el mapeo vive en un solo sitio, dentro del campo de HubSpot, justo debajo del botón de escaneo. Cada línea empareja:

```
[ Campo del sitio ▾ ] [ propiedad (keyid) ]
☰  CONTACTO                             [×]
```

- El **campo del sitio** es un desplegable con los campos del formulario de Elementor (los obligatorios llevan `*`). Si una línea apunta a un campo que se borró, se conserva marcada como *campo eliminado* para que la arregles.
- La **propiedad** es texto libre con sugerencias (datalist) de las propiedades del último escaneo. Si escribes una que no está en el formulario de HubSpot, la línea avisa: HubSpot rechazaría el envío.
- El **objeto** (Contacto, Empresa, Negocio, Ticket) se muestra debajo como información: no se elige aquí porque lo define la propiedad en HubSpot. El escaneo lo toma de la definición del formulario; para una propiedad escrita a mano se asume Contacto.
- **+ Añadir mapeo** para crear líneas a mano y **×** para quitarlas.
- **☰ Arrastrar** para reordenar las líneas. Ese orden es el que se usa al enviar a HubSpot, así que puedes agrupar los campos como quieras verlos.

Lo rellena el escaneo, pero es totalmente editable. Un campo sin línea en el grupo no se envía.

> Si vienes de una versión anterior del plugin, el mapeo que tuvieras dentro de cada campo se sigue respetando al enviar, y el primer escaneo lo recoge y lo pasa al grupo.

El auto-mapeo compara ID del campo, etiqueta y tipo contra el nombre, la etiqueta y el tipo de cada propiedad, con un diccionario de sinónimos ES/EN (`Correo electrónico → email`, `Apellidos → lastname`, `Empresa → company`…), sin acentos ni mayúsculas, y asigna de forma voraz empezando por los emparejamientos más seguros: nunca repite propiedad ni sobrescribe lo que ya escribiste. Amplía el diccionario con el filtro `hef/matcher_synonyms`.

> Nota: HubSpot exige que el formulario de destino tenga esas propiedades entre sus campos; si no, responde `400` indicando cuál sobra.

### 3. Distinguir en HubSpot los envíos del plugin

HubSpot **rechaza con `400` cualquier propiedad que no exista en el formulario de destino**, así que una marca de origen no puede viajar como campo salvo que crees uno oculto en HubSpot. Lo que sí acepta y guarda tal cual es el **contexto del envío**, y ahí es donde el plugin deja la huella.

En el campo de configuración, sección **Huella de origen**:

- **Etiqueta de origen** — por defecto `wp-elementor`. Vacío = no marcar. Útil poner algo propio por sitio o por landing (`web-corporativa`, `landing-bf`).
- **Dónde se marca** — *URL y nombre de página* (por defecto), solo URL, o solo nombre.

Resultado de un envío desde WordPress:

```
pageUri  : https://tusitio.com/contacto/?hs_form_source=wp-elementor
pageName : Contacto [wp-elementor]
```

Un envío hecho desde `share.hsforms.com` llega sin ese parámetro y con la página propia de HubSpot, así que quedan separados.

**Dónde se ve en HubSpot:**

- Marketing → Formularios → tu formulario → envíos: columna con la URL de la página.
- Ficha del contacto → cronología: *"Envió el formulario X en …"* con la URL y el nombre marcados.
- Listas y workflows: al filtrar por *envío de formulario* puedes acotar por la página del envío (`contiene hs_form_source=wp-elementor`).
- Informes de formularios con desglose por página.

**Límite honesto:** al no ser un campo, esto **no** crea una propiedad de contacto. Se puede ver, filtrar y segmentar por la página del envío, pero no aparecerá como columna en la tabla de contactos ni podrás usarla como propiedad en una ficha. Si necesitas eso, las únicas vías son un campo oculto en el formulario de HubSpot (mapeado con *Valores fijos adicionales*: `origen|wp-elementor`) o duplicar el formulario en HubSpot para uso exclusivo de la web — las dos implican configurarlo allí.

### 4. Escáner (HubSpot Forms → Escáner)

Pantalla independiente para inspeccionar cualquier formulario de HubSpot antes de mapearlo: propiedades, keyid, tipo, objeto, obligatoriedad y valores admitidos en las listas desplegables.

### 5. Ajustes globales (HubSpot Forms → Ajustes)

Token de app privada (con instrucciones paso a paso desplegables y botón **Probar token**), reintentos automáticos, intentos máximos, retención de registros, timeout y avisos de error en el editor.

#### Cómo obtener el token de app privada

1. Entra en HubSpot con un usuario **superadministrador**.
2. Icono ⚙ **Configuración** (arriba a la derecha).
3. Menú lateral → **Integraciones → Aplicaciones privadas** (`app.hubspot.com/private-apps`; cuentas europeas `app-eu1.hubspot.com/private-apps`).
4. **Crear una aplicación privada** → pestaña *Información básica* → nombre, p. ej. `WordPress – Elementor Forms`.
5. Pestaña **Alcances (Scopes)** → marca **`forms`** (imprescindible). Opcionales: `crm.objects.contacts.read` / `.write`.
6. **Crear aplicación** → *Continuar creando* → **Mostrar token** y cópialo (`pat-na1-…` / `pat-eu1-…`). HubSpot solo lo muestra entero una vez.
7. Pégalo en Ajustes y pulsa **Probar token**, o mejor defínelo en `wp-config.php`:
   `define( 'HEF_HUBSPOT_TOKEN', 'pat-na1-…' );`

## Registros y reintentos

**HubSpot Forms → Registros** muestra fecha (local y UTC), formulario, página, portal/form de destino, estado, nº de intentos, el JSON enviado y la respuesta literal de HubSpot. Se puede **filtrar por formulario, por página y por rango de fechas**, además de buscar por texto. El listado pagina de **20 en 20** (ajustable en *Opciones de pantalla*, arriba a la derecha), así que la pantalla no se carga por muchos registros que haya.

### Ida y vuelta con los envíos de Elementor

Con *Collect Submissions* activo, los dos registros quedan enlazados en ambos sentidos:

- **Del registro al envío:** cada fila muestra *"Envío de Elementor #123"* y abre `admin.php?page=e-form-submissions#/123`.
- **Del envío al registro:** dentro del envío aparece una fila **HubSpot** con el número de registro, el resultado y la URL directa (`admin.php?page=hef-logs&hef_id=12`, que filtra el registro por ese ID).

La anotación reutiliza la fila que el propio campo de configuración deja en el envío —esa que salía vacía y con nombre ilegible tipo `field_47fad0c`— y la renombra a "HubSpot". Si no existe, añade una. Se puede desactivar en Ajustes.

### Activar el envío: la acción "HubSpot"

El envío se activa **añadiendo la acción HubSpot** en *Acciones después de enviar* (pestaña Ajustes del formulario). Es el único interruptor:

- **Con la acción** → se envía, y el resultado aparece en el *Actions Log* de cada envío de Elementor, junto a Email o Redirect.
- **Sin la acción** → no se envía nada. Pero el intento **se registra igualmente** como *Sin enviar*, con el payload completo y el endpoint, así que no se pierde ningún dato: en cuanto añadas la acción puedes mandar los pendientes con **Reintentar** desde el registro.

El campo de configuración avisa en el editor cuando falta la acción. La configuración sigue viviendo en el campo; la acción no añade ajustes propios.

> El reenvío manual desde el registro del plugin funciona siempre, tenga o no la acción el formulario: va directo a la API con los datos guardados.

- **Reintento manual:** enlace *Reintentar* en la fila o acción en lote.
- **Verificar en HubSpot:** enlace en la fila que consulta la API (`form-integrations/v1/submissions`) y responde si ese envío consta en HubSpot y con qué fecha. Necesita el token de app privada. Útil porque la pantalla de envíos de HubSpot tarda unos minutos en indexar y filtra por rango de fechas en la zona horaria del portal.
- Cada registro muestra la hora local **y la hora UTC**, para comparar con la que enseña HubSpot sin equivocarse de día.
- **Reintento automático:** cron cada 5 min sobre los envíos con error reintentable (red, timeout, `429`, `5xx`) con espera 2, 4, 8… minutos hasta 1 h y un máximo de intentos configurable.
- Los errores `400`/`404` (configuración o payload incorrectos) no se reintentan solos porque volverían a fallar, pero sí se pueden reintentar a mano tras corregir.
- Un cron diario borra los registros correctos más antiguos que el periodo de retención.

## Hooks para desarrolladores

```php
// Modificar el payload antes del envío.
add_filter( 'hef/payload', function ( $payload, $record, $config ) {
    $payload['fields'][] = array( 'objectTypeId' => '0-1', 'name' => 'origen', 'value' => 'web' );
    return $payload;
}, 10, 3 );

// IP real detrás de un proxy/CDN.
add_filter( 'hef/client_ip', function ( $ip ) {
    return isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $ip;
} );

// Token fuera de la base de datos (wp-config.php).
define( 'HEF_HUBSPOT_TOKEN', 'pat-eu1-...' );
```

Otros: `hef/endpoint`, `hef/request_args`, `hef/access_token`, `hef/after_submit`, `hef/after_retry`, `hef/matcher_synonyms`, `hef/source_param` (nombre del parámetro de la huella, por defecto `hs_form_source`), `hef/source_context`.

## Estructura

```
hubspot_elementor_form/
├── hubspot_elementor_form.php     Cabecera, constantes y autoloader
├── uninstall.php
├── assets/css/admin.css
└── includes/
    ├── class-plugin.php           Bootstrap y registro del campo
    ├── class-settings.php         Ajustes globales
    ├── class-api.php              Cliente Forms API v3 y prueba de token
    ├── class-form-handler.php     Construcción del payload y envío
    ├── class-logger.php           Tabla de registros
    ├── class-retry.php            Cron, backoff y reenvíos
    ├── class-scanner.php          Lectura de la URL y definición del formulario
    ├── class-matcher.php          Auto-emparejamiento de campos
    ├── class-ajax.php             Endpoint del escáner
    ├── fields/class-config-field.php   Campo y controles de Elementor
    ├── controls/class-mapping-control.php  Control del grupo de mapeos
    ├── actions/class-hubspot-action.php    Acción de Elementor (Actions Log)
    ├── class-verifier.php         Comprobación del envío contra HubSpot
    └── admin/                     Menú, ajustes, escáner, listado y vistas
```
