@echo off
setlocal

rem Deja la demo LOCAL sin datos de ejemplo para las pruebas reales:
rem   1. borra la base de datos y los archivos subidos (docker compose down -v)
rem   2. arranca de nuevo con AUTO_SEED=false (sin usuarios, visitas ni historial de ejemplo)
rem   3. crea un administrador con contrasena propia (visitorportal:install --create-admin)
rem Despues se cargan los CSV (trabajadores, ausencias, flota, vehiculos del personal) desde Administracion.

set ENV_FILE=.env.demo
set COMPOSE=docker compose --env-file %ENV_FILE% -f docker-compose.demo.yml -f docker-compose.es.yml -f docker-compose.limpio.yml
set APP_URL=http://localhost:8080

echo ============================================================
echo  ESTO BORRA TODA LA BASE DE DATOS LOCAL Y LOS ARCHIVOS SUBIDOS.
echo  Los datos de ejemplo (personas, flota, historial) se pierden.
echo ============================================================
set /p ANSWER=Escriba LIMPIAR para continuar:
if /I not "%ANSWER%"=="LIMPIAR" (
    echo Cancelado. No se borro nada.
    exit /b 0
)

if not exist "%ENV_FILE%" (
    if exist ".env.demo.example" copy ".env.demo.example" "%ENV_FILE%" >nul
)

docker info >nul 2>&1
if errorlevel 1 (
    echo Docker no esta en ejecucion. Abra Docker Desktop y vuelva a ejecutar este archivo.
    exit /b 1
)

echo [1/4] Borrando la base de datos y los archivos subidos...
%COMPOSE% down -v
if errorlevel 1 exit /b 1

echo [2/4] Arrancando sin datos de ejemplo...
%COMPOSE% up -d
if errorlevel 1 exit /b 1

echo [3/4] Esperando a que el portal responda...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$url='%APP_URL%'; for ($i=1; $i -le 90; $i++) { try { $r = Invoke-WebRequest -UseBasicParsing -Uri $url -TimeoutSec 2; if ($r.StatusCode -lt 500) { exit 0 } } catch {}; Start-Sleep -Seconds 2 }; exit 1"
if errorlevel 1 (
    echo El portal no respondio a tiempo. Revise: %COMPOSE% logs --tail=120 app
    exit /b 1
)

echo [4/4] Creando roles, permisos y el administrador. Se le pedira correo, nombre y contrasena:
%COMPOSE% exec app php artisan visitorportal:install --create-admin
if errorlevel 1 exit /b 1

echo.
echo Listo. Entre a %APP_URL% con el administrador que acaba de crear.
echo Siguiente paso: crear usuarios (vigilantes y supervisores) y cargar los CSV desde Administracion.
echo Nota: la base de pruebas de PHPUnit y las dependencias de desarrollo tambien se perdieron;
echo       vea pruebas-manuales\run_phpunit.sh para volver a prepararlas si hace falta.

endlocal
