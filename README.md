# Plugin de Pasarela de Pagos Globalpay para Prestashop

## Instalación

> **Importante para instalaciones junto a otros plugins Globalpay:**  
> Al descargar el ZIP de este repositorio, renombra la carpeta a un nombre soportado distinto de `pg_prestashop_plugin` para evitar conflictos.

### Nombres de carpeta soportados actualmente

- `pg_prestashop_plugin`
- `globalpay_payment`
- `pg_globalpay_plugin`

1. Descarga el ZIP del repositorio
2. Descomprime y renombra la carpeta (recomendado): `pg_prestashop_plugin` → `pg_globalpay_plugin`
3. Sube la carpeta a `/modules/` en tu servidor PrestaShop
4. Ve a Back Office → Módulos → Busca "Globalpay" → Instalar

## 1. Requisitos previos
### 1.1. XAMPP, LAMPP, MAMPP, Bitnami o cualquier entorno de desarrollo PHP
- XAMPP: https://www.apachefriends.org/download.html
- LAMPP: https://www.apachefriends.org/download.html
- MAMPP: https://www.mamp.info/en/mac/
- Bitnami: https://bitnami.com/stack/prestashop
### 1.2. Prestashop
Atención: si ya instalaste la opción Bitnami, este paso se puede omitir.

Prestashop es una solución de comercio electrónico desarrollada en PHP.
Este plugin es compatible con **PrestaShop 8.0.0 y versiones más recientes** (8.x y 9.x).
- Descarga: https://www.prestashop.com/en/download
- Guía de instalación: https://www.prestashop.com/en/blog/how-to-install-prestashop

## 2. Repositorio Git
Puedes descargar la versión estable actual desde: https://github.com/globalpayredeban/pg_prestashop_plugin/releases

## 3. Instalación del plugin en Prestashop
1. Primero, descarga la versión estable actual del plugin Globalpay para Prestashop desde el paso anterior.
2. Descomprime el archivo y ubica la carpeta del plugin.
3. Renombra la carpeta a **globalpay_payment**.
4. Comprime la carpeta en formato zip para obtener un archivo llamado **globalpay_payment.zip**.
5. Inicia sesión en la página de administración de Prestashop.
6. Haz clic en **Mejorar → Módulos → Administrador de módulos**.
7. En el Administrador de módulos haz clic en el botón **Subir un módulo**.
8. Haz clic en **Seleccionar archivo**, o puedes **arrastrar** la carpeta del plugin Globalpay para Prestashop en formato .zip o .rar.
9. Espera hasta que la pantalla **Instalando módulo** cambie a **¡Módulo instalado!**.
10. Ahora puedes hacer clic en el botón **Configurar** que aparece en la pantalla, o en el botón **Configurar** que aparece en la sección **Pago** del **Administrador de módulos**.
11. Dentro de **Configuración de la pasarela de pagos** debes configurar las credenciales proporcionadas por **Globalpay**. También puedes seleccionar el **Idioma del checkout** y el **Ambiente** (STG por defecto).
12. ¡Felicitaciones! Ahora tienes el plugin Globalpay para Prestashop correctamente configurado.

## 4. Consideraciones y comentarios
### 4.1. Reembolsos
- El plugin soporta **Reembolsos parciales** y **Reembolsos estándar** para pagos con **Tarjeta**.
- Las órdenes **LinkToPay** no son reembolsables desde el flujo del back-office del plugin.
- El **Reembolso estándar** envía el monto generado por PrestaShop. Si no se proporciona un monto parcial, se usa por defecto el monto total pagado de la orden.
### 4.2. Webhook
El plugin Globalpay para Prestashop cuenta con un webhook interno para mantener actualizados los estados de las transacciones entre Prestashop y Globalpay. Debes seguir estos pasos para configurar el webhook:
1. Inicia sesión en el Back-office de Prestashop.
2. Navega a **Parámetros avanzados → Servicios web** para abrir la página de Servicios web.
3. Serás redirigido a la página de Servicios web con el listado de servicios disponibles y el formulario de configuración.
4. Habilita el campo llamado **Habilitar el servicio web de Prestashop**.
5. Haz clic en el botón **Guardar**.
6. El módulo crea automáticamente la clave del servicio web **globalpaywebhook** durante la instalación.
7. Edita esa clave en **Parámetros avanzados → Servicios web** y habilita manualmente el permiso **POST** para **globalpaywebhook**.
8. Puedes revisar/copiar la clave generada en esa misma pantalla.
9. El webhook está ubicado en **https://{mistiendaurl}/api/globalpaywebhook?ws_key=LA_CLAVE_CREADA_POR_EL_MODULO**.
10. Debes proporcionar esta URL a tu agente de Globalpay.

## 5. Actualizaciones de seguridad y flujo
- La inicialización de Tarjeta y LinkToPay se realiza del lado del servidor para evitar exponer llaves sensibles del servidor en el navegador.
- Las solicitudes front desde el checkout ahora usan una firma de seguridad (`pg_sig`) validada por los controladores del backend.
- Se endureció la validación del payload del webhook (campos requeridos, verificación del código de aplicación, verificación del stoken y validación de consistencia del monto).
