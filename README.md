AtIVR
=====
Por ahora esta operando en la ruta /opt/AtIVR la cambiaré luego.

Para ejecutar este programa podemos utilizar por ejemplo una linea como:

root@ubuntu-ivr:/opt/AtIVR$ php robot.php --obj-id=50363 --monitor-id=1051

si agregamos al path de sesion el directorio /opt/AtIVR podemos ejecutar directamente

robot.php --obj-id=50363 --monitor-id=1051


Software externo que utiliza el sistema:
- PHP
- sox
- Librerías PostgreSQL para conectarse a BBDD Atentus
- pHash y módulo para PHP
- Memcached, el cual esta corriendo localmente
