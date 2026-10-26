# Plugin de pagos con Stripe y HubSpot para Elementor / Stripe & HubSpot Payments for Elementor

[Español](#español) · [English](#english)

---

## Español

Plugin de WordPress para **cobrar con Stripe desde formularios de Elementor**, tanto pagos únicos como suscripciones mensuales, y registrar cada pago exitoso en **HubSpot**.

**Versión:** 0.6.7

### Qué hace

- **Cobros con Stripe Checkout:** pago único y suscripción mensual. Los datos de tarjeta nunca pasan por el servidor del sitio.
- **Seguro por diseño:** el importe se valida en el servidor según la plantilla de pago, y un pago solo se marca como pagado cuando llega el evento de Stripe con su firma verificada.
- **Plantillas de pago:** por formulario se definen montos permitidos, correos de confirmación, páginas de resultado (gracias / fallo) y reportes.
- **HubSpot:** crea o actualiza el contacto y el negocio solo cuando el pago fue exitoso, y sincroniza el estado de las suscripciones. Los formularios sin cobro también pueden enviarse a HubSpot.
- **Portal del donante/cliente** para actualizar su método de pago o cancelar la suscripción.
- **Administración:** listados de transacciones y suscripciones, bitácora de envíos con reintentos, alertas por correo y reportes programados.
- **Biblioteca de formularios** de Elementor lista para importar.
- Bilingüe (español / inglés).

### Requisitos

- WordPress 6.4 o superior
- PHP 8.1 o superior
- Elementor y Elementor Pro (formularios)
- Una cuenta de Stripe y, si se usa, una app privada de HubSpot

### Instalación

1. En esta página, abre el botón verde **Code** y elige **Download ZIP**.
2. En WordPress, ve a *Plugins → Añadir nuevo → Subir plugin*, elige el ZIP y pulsa **Instalar ahora**.
3. Activa el plugin.
4. En *Pagos Stripe → Configuración*, ingresa las claves de Stripe y el secreto del webhook. En Stripe, apunta el webhook a la URL que muestra esa pantalla.
5. Crea una plantilla de pago, asígnala a un formulario de Elementor y agrega la acción **Cobro con Stripe** al formulario.

### Desarrollo

Desarrollado por **Fer Santizo** · [github.com/Fer1605](https://github.com/Fer1605)

### Licencia

GPL-2.0-or-later

---

## English

WordPress plugin to **take Stripe payments from Elementor forms**, both one-time payments and monthly subscriptions, and record every successful payment in **HubSpot**.

**Version:** 0.6.7

### Features

- **Stripe Checkout payments:** one-time payments and monthly subscriptions. Card data never touches the site's server.
- **Secure by design:** the amount is validated on the server against the payment template, and a payment is only marked as paid when Stripe's signed event arrives and its signature is verified.
- **Payment templates:** per form, define the allowed amounts, confirmation emails, result pages (thank you / failure) and reports.
- **HubSpot:** creates or updates the contact and the deal only after a successful payment, and keeps subscription status in sync. Forms without a payment can also be sent to HubSpot.
- **Donor/customer portal** to update the payment method or cancel the subscription.
- **Administration:** transaction and subscription lists, delivery log with retries, email alerts and scheduled reports.
- **Elementor form library** ready to import.
- Bilingual (Spanish / English).

### Requirements

- WordPress 6.4 or later
- PHP 8.1 or later
- Elementor and Elementor Pro (forms)
- A Stripe account and, if used, a HubSpot private app

### Installation

1. On this page, open the green **Code** button and choose **Download ZIP**.
2. In WordPress, go to *Plugins → Add New → Upload Plugin*, choose the ZIP and click **Install Now**.
3. Activate the plugin.
4. In *Pagos Stripe → Configuración* (Settings), enter your Stripe keys and the webhook secret. In Stripe, point the webhook to the URL shown on that screen.
5. Create a payment template, assign it to an Elementor form and add the **Cobro con Stripe** (Stripe payment) action to the form.

### Author

Developed by **Fer Santizo** · [github.com/Fer1605](https://github.com/Fer1605)

### License

GPL-2.0-or-later
