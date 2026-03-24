###

A continuación explicamos la estructura de esta carpeta TFC enlazada a Git.

###

Esta es una breve explicación de las carpetas que nos interesa usar. El resto mejor ni tocarlos

/TFC

    /backend -> Toda la lógica php que vamos a usar
        instalador.php
        conexion.php
        /uploads -> directorio al que se subirán las imágenes que subamos a la web            
        /api -> directorio para las peticiones como los getProducto, getUsuario...

    /frontend -> Todo el proyecto de Astro -> Para verlo en el navegador abrimos la terminal desde /frontend y hacemos npm run dev
        /components -> componentes
        /layouts -> layouts o plantillas
        /pages -> paginas concretas

### IMPORTANTE ###

PARA HACER ACCESIBLE LA BASE DE DATOS MANTENIENDO EL BACKEND AQUI:

IR A C:\xampp\apache\conf\httpd.conf

AÑADIR ESTE BLOQUE AL FINAL:

Alias /tfc "Ruta" (Tu ruta en la que tienes la carpeta repo de git)

<Directory "Ruta">
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

El código está comentado para dudas
        
