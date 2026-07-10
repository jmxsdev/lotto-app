### ESTRUCTURA DEL MONOREPO (Raíz del proyecto)

```
lotto-app/                           # Raíz del proyecto
├── docker-compose.yml               # Orquestación de servicios (MySQL, Redis, Nginx)
├── .env.example                     # Variables de entorno de ejemplo para todo el stack
├── README.md                        # Documentación general del proyecto
├── deploy.sh                        # Script de despliegue (opcional)
│
├── backend/                         # 🟦 BACKEND + DASHBOARD ADMIN (Laravel)
│   ├── app/
│   │   ├── Console/
│   │   │   ├── Commands/            # Comandos Artisan personalizados
│   │   │   └── Kernel.php           # Programación de tareas (cron)
│   │   ├── Exceptions/              # Manejador de excepciones
│   │   ├── Http/
│   │   │   ├── Controllers/         # Controladores API y Web
│   │   │   │   ├── Api/             # Endpoints para la taquilla (v1/)
│   │   │   │   │   ├── AuthController.php
│   │   │   │   │   ├── ApuestaController.php
│   │   │   │   │   ├── PagoController.php
│   │   │   │   │   ├── CierreController.php
│   │   │   │   │   └── ActivacionController.php
│   │   │   │   └── Admin/           # Controladores del Dashboard (Livewire o Inertia)
│   │   │   │       ├── UserController.php
│   │   │   │       ├── BancaController.php
│   │   │   │       ├── JuegoController.php
│   │   │   │       └── TasaController.php
│   │   │   ├── Middleware/          # Middlewares (VerifyMac, CheckRole, etc.)
│   │   │   ├── Requests/            # Form Requests (validaciones)
│   │   │   └── Resources/           # Transformación de datos (API Resources)
│   │   ├── Jobs/                    # Trabajos encolados (Scrapers, Comisiones, Cierres)
│   │   │   ├── FetchResultsJob.php
│   │   │   ├── CalculateCommissionsJob.php
│   │   │   └── ScrapeExchangeRateJob.php
│   │   ├── Listeners/               # Eventos y Listeners (para logs de auditoría)
│   │   ├── Mail/                    # Mails de notificación
│   │   ├── Models/                  # Modelos Eloquent
│   │   │   ├── User.php
│   │   │   ├── Banca.php
│   │   │   ├── Grupo.php
│   │   │   ├── Taquilla.php
│   │   │   ├── Apuesta.php
│   │   │   ├── Pago.php
│   │   │   ├── CierreCaja.php
│   │   │   ├── ExchangeRate.php
│   │   │   ├── Comision.php
│   │   │   ├── Juego.php
│   │   │   ├── PluginJuego.php
│   │   │   └── Log.php
│   │   ├── Policies/                # Políticas de autorización (ej. ApuestaPolicy)
│   │   ├── Plugins/                 # 🧩 SISTEMA DE PLUGINS (CORAZÓN MODULAR)
│   │   │   ├── Contracts/           # Interfaces obligatorias
│   │   │   │   └── JuegoInterface.php
│   │   │   ├── Juegos/              # Cada juego es una clase PHP aquí
│   │   │   │   ├── Animalitos.php   # Plugin ejemplo (implementa la interfaz)
│   │   │   │   └── ... (nuevos juegos se agregan aquí sin tocar el core)
│   │   │   └── Scrapers/            # Lógica de scraping específica por juego
│   │   │       ├── BaseScraper.php
│   │   │       └── AnimalitosScraper.php
│   │   ├── Providers/               # Service Providers
│   │   │   ├── AppServiceProvider.php
│   │   │   ├── AuthServiceProvider.php (registra Policies)
│   │   │   └── PluginServiceProvider.php (escanea y registra los plugins)
│   │   └── Services/                # Lógica de negocio pesada
│   │       ├── CierreService.php    # Cálculo de cierre de caja (dual moneda)
│   │       ├── ExchangeRateService.php # Gestión de tasa activa
│   │       └── ComisionService.php  # Cálculo de comisiones
│   ├── bootstrap/                   # Carga inicial de Laravel
│   ├── config/                      # Archivos de configuración
│   │   ├── app.php
│   │   ├── sanctum.php              # Configuración de tokens API
│   │   ├── horizon.php              # Configuración de colas (Redis)
│   │   ├── permission.php           # Configuración de Spatie Roles
│   │   └── exchange.php             # Configuración personalizada (moneda base, etc.)
│   ├── database/
│   │   ├── factories/               # Fábricas para testing
│   │   ├── migrations/              # 📦 TODAS las migraciones del sistema
│   │   │   ├── 2024_..._create_bancas_table.php
│   │   │   ├── 2024_..._create_taquillas_table.php
│   │   │   ├── 2024_..._create_exchange_rates_table.php
│   │   │   └── ...
│   │   └── seeders/                 # Datos de prueba (roles, juegos, etc.)
│   ├── lang/                        # Traducciones (opcional)
│   ├── public/                      # Punto de entrada público (index.php, assets)
│   ├── resources/
│   │   ├── css/                     # CSS global del dashboard
│   │   ├── js/                      # JS del dashboard (si usas Livewire o Vue)
│   │   └── views/                   # 🖥️ DASHBOARD ADMIN (Blade/Livewire)
│   │       ├── admin/
│   │       │   ├── layouts/
│   │       │   ├── users.blade.php
│   │       │   ├── bancas.blade.php
│   │       │   ├── juegos.blade.php
│   │       │   ├── tasas.blade.php
│   │       │   └── reportes.blade.php
│   │       └── auth/                # Login/registro (si aplica)
│   ├── routes/
│   │   ├── api.php                  # 📡 RUTAS PARA LA T AQUILLA (versión /api/v1)
│   │   ├── web.php                  # 🌐 RUTAS PARA EL DASHBOARD ADMIN
│   │   ├── console.php              # Rutas para comandos Artisan
│   │   └── channels.php             # Broadcasting (si aplica)
│   ├── storage/                     # Logs, cache, archivos temporales
│   ├── tests/                       # Pruebas (Unit, Feature, Mocking)
│   │   ├── Unit/
│   │   └── Feature/
│   ├── .env                         # Variables de entorno (no committear)
│   ├── artisan                      # Ejecutable de Laravel
│   ├── composer.json                # Dependencias PHP (Laravel, Spatie, Guzzle, etc.)
│   └── phpunit.xml                  # Configuración de pruebas
│
├── taquilla/                        # 🖥️ FRONTEND DE TAQUILLA (Electron + Astro)
│   ├── package.json                 # Dependencias (Astro, Electron, keytar, etc.)
│   ├── pnpm-lock.yaml / yarn.lock   # Bloqueo de dependencias
│   ├── .env                         # Variables de entorno (URL_API, etc.)
│   ├── .gitignore                   # Ignorar dist/, node_modules/, build/
│   │
│   ├── astro.config.mjs             # Configuración de Astro (build estático)
│   ├── tsconfig.json                # Configuración de TypeScript (Astro + Electron)
│   ├── vite.config.ts               # Configuración Vite (usado por Astro)
│   │
│   ├── src/                         # 📂 CÓDIGO FUENTE DE ASTRO (UI)
│   │   ├── components/              # Componentes reutilizables (Astro/Vue/React)
│   │   │   ├── layout/
│   │   │   │   └── BaseLayout.astro
│   │   │   ├── ui/
│   │   │   │   ├── BotonMoneda.astro # Inputs para BS/USD
│   │   │   │   ├── TicketCard.astro
│   │   │   │   └── CalculadoraPago.astro
│   │   │   └── ...
│   │   ├── layouts/                 # Layouts principales
│   │   │   └── DefaultLayout.astro
│   │   ├── pages/                   # 🚀 RUTAS DE LA APLICACIÓN (Vistas)
│   │   │   ├── index.astro          # Redirige a activacion o login
│   │   │   ├── activacion.astro     # Pantalla de activación por código
│   │   │   ├── login.astro          # Pantalla de login de operador
│   │   │   ├── dashboard.astro      # Menú principal de la taquilla
│   │   │   ├── apuestas/
│   │   │   │   ├── nueva.astro      # Formulario para apostar (pago mixto)
│   │   │   │   └── historial.astro  # Listado de apuestas del turno
│   │   │   ├── resultados.astro     # Consulta de resultados del sorteo
│   │   │   └── cierre.astro         # Resumen y cierre de caja
│   │   ├── styles/                  # CSS/SCSS globales
│   │   │   └── global.css
│   │   ├── utils/                   # Utilidades del frontend
│   │   │   ├── api.ts               # Cliente Axios/Fetch para consumir Laravel
│   │   │   ├── currency.ts          # Funciones de formateo BS/USD
│   │   │   └── validators.ts
│   │   └── store/                   # Estado global (Zustand/Pinia/Signals)
│   │       └── authStore.ts
│   │
│   ├── public/                      # Archivos estáticos (favicon, logos)
│   │   └── logo.png
│   │
│   ├── electron/                    # ⚡ CÓDIGO DE ELECTRON (NATIVO)
│   │   ├── main/
│   │   │   ├── main.js              # Proceso principal (ventanas, IPC, MAC)
│   │   │   ├── menu.js              # Menú personalizado
│   │   │   └── ipcHandlers.js       # Manejadores de comunicación (ej. getMAC)
│   │   ├── preload/
│   │   │   └── preload.js           # Script de precarga (expone APIs seguras al DOM)
│   │   ├── printers/
│   │   │   └── ticketPrinter.js     # Lógica para imprimir tickets (node-printer)
│   │   └── build/                   # Scripts para empaquetado (electron-builder)
│   │       ├── builder.json         # Configuración del .exe (icono, nombre)
│   │       └── afterPack.js         # Hooks post-empaquetado
│   │
│   ├── dist/                        # 📦 ASTRO BUILD (generado automáticamente)
│   │   # (Contiene HTML, CSS, JS estático; NO se sube al repo)
│   │
│   └── build/                       # 📦 ELECTRON BUILD (el .exe final)
│       # (Contiene el instalador de Windows; NO se sube al repo)
│
└── docs/                            # 📄 DOCUMENTACIÓN ADICIONAL
    ├── api/                         # Colección de Postman/Insomnia
    ├── manual-usuario/              # Manual de uso para operadores
    └── arquitectura/                # Diagramas ER y de flujo
```

---

### DETALLE DE CARPETAS CLAVE Y SU PROPÓSITO

| Ruta | ¿Qué va aquí? | ¿Quién lo usa? |
|------|---------------|----------------|
| **`backend/app/Plugins/Juegos/`** | Cada nuevo juego de lotería (ej. `Animalitos.php`, `Raspaditos.php`). Debes implementar la `JuegoInterface`. **Aquí se agregan los plugins sin modificar el núcleo.** | Backend (Laravel) |
| **`backend/app/Plugins/Scrapers/`** | La lógica específica para raspar los resultados oficiales de cada juego. | Jobs de Laravel |
| **`backend/resources/views/admin/`** | Todas las vistas del **Dashboard Administrativo** (Super Master, Master, Banca). | Navegador web (Admin) |
| **`backend/routes/api.php`** | Endpoints que consumirá la app de taquilla (ej. `/api/v1/apuestas`, `/api/v1/activar`). | Frontend (Electron) |
| **`backend/routes/web.php`** | Rutas para el Dashboard Admin (ej. `/admin/usuarios`). | Navegador web (Admin) |
| **`taquilla/src/pages/`** | Cada archivo `.astro` aquí se convierte en una pantalla (ruta) de la taquilla. | Operador de taquilla |
| **`taquilla/electron/main/`** | Código que corre en el proceso principal de Electron. Aquí se lee la **MAC Address**, se maneja la impresión térmica y se carga la UI (que viene del build de Astro). | Sistema operativo (Windows) |
| **`taquilla/electron/preload/`** | Puente seguro entre el proceso principal (Node) y el DOM de Astro. Expone funciones como `window.electron.getMac()` para que el frontend pueda pedir la MAC. | Frontend (Astro) |

---

### ARCHIVOS DE CONFIGURACIÓN ESENCIALES

- **`docker-compose.yml`** (Raíz): Define los contenedores de **MySQL 8**, **Redis**, y **Nginx** (para servir Laravel). Todos los servicios se levantan con un solo comando.
- **`backend/.env`**: Configuración de base de datos, Redis, URL del scraper, tasa de cambio por defecto, y `APP_URL` (para que la taquilla sepa dónde apuntar).
- **`backend/config/exchange.php`**: Archivo personalizado que guardará configuraciones como `'default_currency' => 'VES'` o `'dollar_rate_update_interval' => 60` (minutos).
- **`taquilla/.env`**: Variable `VITE_API_URL=http://backend.local/api/v1` (Astro usa `import.meta.env`). También `ELECTRON_PRINTER_NAME=POS-80` para la impresora.
- **`taquilla/electron/build/builder.json`**: Configuración de `electron-builder`; aquí defines el nombre del `.exe`, la versión, el ícono, y si quieres que sea portable o instalable.

---

### GESTIÓN DEL MONOREPO (Consejos prácticos)

- **Git**: El `.gitignore` en la raíz debe incluir:
  ```gitignore
  backend/vendor/
  backend/.env
  backend/storage/
  taquilla/node_modules/
  taquilla/dist/
  taquilla/build/
  taquilla/.env
  ```
- **Dependencias**: No mezcles `composer` y `npm`. En el `README.md` explica que para iniciar:
  ```bash
  cd backend && composer install && php artisan migrate
  cd ../taquilla && npm install && npm run build && npm run electron:build
  ```
- **Comunicación entre Astro y Electron**:
  - El `package.json` de `taquilla/` tendrá scripts como:
    ```json
    "scripts": {
      "dev": "astro dev",
      "build": "astro build",
      "electron:dev": "npm run build && electron .",
      "electron:build": "npm run build && electron-builder"
    }
    ```

Esta estructura te permite trabajar de forma aislada (el equipo de backend puede ignorar la carpeta `taquilla/` y viceversa), pero manteniendo todo el código fuente centralizado en un solo repositorio para facilitar el despliegue y la trazabilidad.

¿Necesitas que profundice en el contenido de algún archivo específico, como el `PluginServiceProvider` o el `preload.js`?
