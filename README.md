# Territorio

Plataforma multi‑campaña para gestión territorial, red de referidos, agenda, logística, inventario y analítica.

## Primera entrega disponible

- Organizaciones, campañas, membresías y roles configurables.
- Aislamiento estricto de datos por campaña.
- Elecciones y snapshots DIVIPOL.
- Personas con contacto y cédula cifrados.
- Consentimiento explícito, versionado y auditable.
- Enlaces públicos con tokens almacenados mediante hash.
- Registro público con puesto, mesa y PDF de cédula cifrado.
- Red territorial con un padre activo, prevención de ciclos y grafo interactivo.
- Dashboard ejecutivo y bandeja de decisiones.
- Solicitud y aprobación de reuniones con detección de cruces.
- Esquema completo inicial para delegados, asistencia, inventario, reservas y faltantes.
- API idempotente protegida para integración con `testiapp`.
- PWA instalable, PostgreSQL/PostGIS, Redis, Metabase opcional y backups cifrados.

## Ejecución local

```bash
composer install
npm install
php artisan migrate:fresh --seed
npm run build
composer run dev
```

Acceso demostrativo:

- URL: `http://localhost:8000`
- Usuario: `admin@territorio.test`
- Contraseña: `password`
- Invitación pública: `http://localhost:8000/public/v1/invitations/demo-villavicencio-2027`

Los datos de la semilla son ficticios y solo sirven para demostrar los flujos.

## Pruebas

```bash
php artisan test
npx tsc --noEmit
npm run build
```

Las pruebas cubren aislamiento entre campañas, tokens revocados, registro público, cifrado documental y prevención de ciclos.

## Homelab con Docker

1. Copiar `.env.docker.example` a `.env.docker` y reemplazar todas las credenciales.
2. Generar `APP_KEY` con `php artisan key:generate --show`.
3. Configurar el remoto `gdrive` de rclone usando `docker/backup/rclone.conf.example`.
4. Definir una contraseña robusta en `BACKUP_ENCRYPTION_PASSWORD`.
5. Levantar los servicios:

```bash
docker compose --env-file .env.docker up -d --build
docker compose --env-file .env.docker run --rm app php artisan migrate --force
```

Metabase es opcional:

```bash
docker compose --env-file .env.docker --profile analytics up -d metabase
```

El servicio de backup genera una copia PostgreSQL cifrada cada cuatro horas, la envía a Google Drive y usa Telegram únicamente para informar éxito o fallo.

## Seguridad operativa

- No publicar `storage/app/private` ni el archivo `.env`.
- HTTPS es obligatorio fuera del equipo local.
- Cambiar inmediatamente las credenciales demostrativas.
- Mantener el backup cifrado y la clave en ubicaciones diferentes.
- Probar restauraciones periódicamente.
- Antes de usar datos reales, aprobar jurídicamente el texto de consentimiento, la política de tratamiento y la retención de documentos.
