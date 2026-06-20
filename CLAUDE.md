# protrack-webservices

Laravel 11 app que toma posiciones GPS de unidades vehiculares desde la API de **Protrack365** y las retransmite a entidades / proveedores externos (**SUTRAN**, **OSINERGMIN**, **SatelTrack/Tracklog**). UI de administración con Livewire/Jetstream + WireUI/PowerGrid.

## Flujo principal

```
Scheduler (api:get cada 30s)
  -> ApiGetCommand
    -> ProcessUnitsJob (queue: web-services)
      1. Lee Devices con services->sutran->active OR services->osinergmin->active
      2. ProtrackApiService::fetchDeviceLocation() (en chunks de 100 IMEIs)
      3. Processor::processUnits() -> separa en ['sutran' => [...], 'osinergmin' => [...]]
         (descarta si la posición ya fue enviada: compara hearttime vs devices.last_update)
      4. Si Config->servicios[x]['status']: dispatch SendTo{X}Job
        -> UnitTransformer::transform()   (normaliza el array crudo)
        -> {X}Formatter::format()         (mapea al payload exacto que pide el servicio)
        -> {X}Sender::send($tramas, $url) (HTTP, logging, contadores, update Devices)
```

Cada integración sigue el mismo patrón con 3 piezas intercambiables:

| Capa      | Interfaz                                         | Implementaciones                                            |
| --------- | ------------------------------------------------ | ----------------------------------------------------------- |
| Formatter | `App\Services\Formatters\UnitFormatterInterface` | `OsinergminFormatter`, `SutranFormatter`, `SatelTrackFormatter` |
| Sender    | `App\Services\Senders\UnitSenderInterface`       | `OsinergminSender`, `SutranSender`, `SatelTrackSender`       |
| Job       | `ShouldQueue`                                    | `SendToOsinergminJob`, `SendToSutranJob`, `SendToSatelTrackJob` |

## Cómo agregar un nuevo servicio de retransmisión

Para un servicio nuevo (p. ej. "Acme"), replicar el patrón de Sutran/Osinergmin:

1. **Formatter**: `app/Services/Formatters/AcmeFormatter.php` implementa `UnitFormatterInterface`. Recibe unidades normalizadas (`UnitTransformer`) y devuelve el payload exacto que la API externa espera (nombres de campo, formatos de fecha, tipos).
2. **Sender**: `app/Services/Senders/AcmeSender.php` implementa `UnitSenderInterface`. Debe:
    - Usar `Config::first()->servicios['acme']` para token/credenciales.
    - Loguear con `Log::channel('acme')` (registrar el canal en `config/logging.php`, mismo patrón `daily` que `osinergmin`/`sutran`).
    - Llamar a `LogService::logToDatabase()` por trama si `enabled_logs` está activo, para que aparezca en el módulo de Logs (`app/Livewire/Logs`).
    - Actualizar `Devices` (`last_status`, `last_position`, `last_update`, `latest_position_id`) en éxito.
    - Actualizar contadores vía `Config->counterServices()` (patrón `updateCounterService` de `OsinergminSender`).
3. **Job**: `app/Jobs/SendToAcmeJob.php`, cola dedicada `web-services-acme` (`onQueue` en el constructor) para no bloquear las otras colas.
4. **Processor**: en `app/Services/Processors/Processor.php`, añadir `'acme' => []` al array `$result` y la rama `if ($device->services['acme']['active'] ?? false) { ... }`.
5. **ProcessUnitsJob**: añadir `acme` a la condición `where(...)` de dispositivos elegibles y el bloque `if ($config->servicios['acme']['status']) { SendToAcmeJob::dispatch(...); }`.
6. **Config**:
    - Migración/seeder: agregar `servicios.acme = ['token' => '', 'status' => 0, 'enabled_logs' => 0]` a la estructura JSON de la tabla `config` (ver `database/seeders/UserSeeder.php`).
    - `app/Livewire/Web/Config.php`: método `saveServicioAcme()` (validación de token) + UI en la vista Blade correspondiente para activar/editar el servicio.
    - `Devices.services`: cada dispositivo necesita `acme.active` en su JSON (se inicializa en `Config::fetchAndStoreDevices()`).
7. **Colas**: agregar `web-services-acme` a la lista de `ClearQueues::handle()` y a `QueueManager` (`app/Utils/QueueManager.php`) si expone rutas de limpieza/estadísticas por cola.

No reutilizar lógica de "ajuste"/normalización de velocidad de `Processor::adjustSpeed()` en nuevas integraciones — no aplica a otros servicios.

## Modelos clave

- **Config** (`config`, singleton vía `Config::first()`): credenciales Protrack (`cuenta`/`clave`) + `servicios` (JSON: `{servicio}.token`, `.status`, `.enabled_logs`). Relación `morphOne` con `CounterServices`.
- **Devices** (`devices`): `imei`, `plate`, `services` (JSON por dispositivo: `{servicio}.active`), `last_status`, `last_position`, `last_update`, `latest_position_id`.
- **CounterServices** (`counters_services`, polimórfico): contadores acumulados `sent`/`success`/`failed`/`last_error`/`last_attempt` por servicio.
- **Logs** (`logs`): historial de cada trama enviada (`service_name`, `plate_number`, `request`, `response`, `status`, `fecha_hora_posicion`, `imei`) — alimenta `app/Livewire/Logs`.

## Jobs y colas

| Job                   | Cola                      | Disparo                                                                                                                                      |
| --------------------- | ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `ProcessUnitsJob`     | `web-services`            | scheduler `api:get` cada 30s                                                                                                                 |
| `SendToSutranJob`     | `web-services-sutran`     | desde `ProcessUnitsJob`                                                                                                                      |
| `SendToOsinergminJob` | `web-services-osinergmin` | desde `ProcessUnitsJob`                                                                                                                      |
| `SendToSatelTrackJob` | `web-services-sateltrack` | desde `ProcessUnitsJob`                                                                                                                      |
| `ReenviarHistorial`   | `reenviar-historial`      | manualmente desde `app/Livewire/Web/ReenvioHistorialModal.php` (Osinergmin). El disparo automático por gap >10 min está **comentado** en `Processor.php` |
| `ReenviarHistorialSatelTrack` | `reenviar-historial` | manualmente desde `ReenvioHistorialModal.php` (cuando `service = sateltrack`)                                                              |
| `ClearLogs`           | —                         | scheduler diario, borra `logs` > N días                                                                                                      |
| `ClearQueues`         | —                         | scheduler diario 20:00                                                                                                                       |

| `ProcessSatelTrackAlarmsJob` | `web-services-sateltrack` | scheduler `sateltrack:alarms` cada 1 min (polling de alarmas 134/135/143) |

Conexión de cola configurada vía `QUEUE_CONNECTION` (default `database`). `RefreshToken` (`protrack:refresh-token`) renueva el access token de Protrack cacheado (`Cache::put('protrack_api_access_token', ...)`), corre cada hora.

## Integración Protrack365

`App\Services\Api\ProtrackApiService` envuelve el SDK `jhamnerx/protrack-api`. Maneja auth (token cacheado), `fetchDevices`, `fetchDeviceLocation`, `fetchPlayback` (paginado, usado para reenvío de historial). Base URI: `http://api.protrack365.com`.

## Logging

Canales dedicados en `config/logging.php` (driver `daily`, 7 días): `osinergmin`, `sutran`, `sateltrack`, `data_quality`. Cada Sender loguea inicio/fin de batch, éxito/error por trama y tasa de éxito; además persiste en BD vía `LogService` si `servicios.{x}.enabled_logs` está activo.

## Servicio SatelTrack (Tracklog)

Integración con el WS de inserción de transmisiones de Tracklog S.A.C. (rama `sateltrack`).

- **Config**: `servicios.sateltrack = { url, status, enabled_logs }`. A diferencia de Sutran/Osinergmin (que usan `token`), SatelTrack usa una **URL configurable** por EMV/Transportista (no requiere token). Se edita en `app/Livewire/Web/Config.php::saveServicioSatelTrack()` + tarjeta en `config.blade.php`.
- **Payload** (POST, body JSON con bloque `items`, máx **200 regs** por envío, hora local GMT-5): `placa` (con guion), `fechaEvento` (Y-m-d), `horaEvento` (H:i:s), `latitud`/`longitud` (string), `direccion` (int), `velocidad` (int kph), `evento` (string), `odometro` (int km). Ver `SatelTrackFormatter`.
- **Mapeo de evento** (`SatelTrackFormatter::resolveEvento`): se deriva del campo `accstatus` de Protrack — `1`=ACC ON→`"501"` (Motor Encendido), `0`=ACC OFF→`"500"` (Motor Apagado). Si `accstatus` es `-1`/ausente se infiere por velocidad: `speed > 0`→`"501"`, si no→`"2"` (Posición). En el reenvío de historial (`ReenviarHistorialSatelTrack`) el playback no trae `accstatus`, por lo que usa `"2"` fijo.
- **Eventos de conducción (134 Frenado Brusco / 135 Aceleración Brusca / 143 Giro Peligroso)**: se obtienen del endpoint de alarmas de Protrack (`/api/alarm/list` vía `ProtrackApiService::fetchAlarms`), NO se calculan. Mapeo `alarmType` Protrack → evento Tracklog: `23`→`135`, `24`→`134`, `25`→`143` (`ProcessSatelTrackAlarmsJob::ALARM_EVENT_MAP`).

### Pipeline de alarmas (polling automático)

```
Scheduler (sateltrack:alarms cada 1 min, withoutOverlapping)
  -> SatelTrackAlarmsCommand
    -> ProcessSatelTrackAlarmsJob (queue: web-services-sateltrack)
       Para cada device con services->sateltrack->active:
         begin = devices.last_alarm_check ?? (now - 120s);  end = now
         ProtrackApiService::fetchAlarms(imei, begin, end)   (paginado 100/req por systemtime)
         filtra alarmType ∈ {23,24,25} -> formatea a item Tracklog (evento 135/134/143)
         SatelTrackSender(updateDevices: false)->send(chunk 200, url)
         devices.last_alarm_check = now   (cursor anti-duplicados)
```

- **`updateDevices: false`**: el `SatelTrackSender` NO actualiza `last_update`/`latest_position_id` al enviar alarmas, para no interferir con la deduplicación de posiciones del flujo principal (`Processor` compara `hearttime` vs `last_update`).
- **`devices.last_alarm_check`** (columna `unsignedInteger` nullable, migración `2026_06_20_000001`): guarda hasta qué `systemtime` (unix) ya se procesaron alarmas. Solo avanza si la consulta fue exitosa (ante error se reintenta el mismo rango).
- Las alarmas sí cuentan en `CounterServices` (son transmisiones), pero se loguean/envían como eventos discretos.
- **Respuesta**: `{ "Recibidos": [ { "Placa", "Fecha_Hora" } ] }`. El `SatelTrackSender` marca como éxito cada trama cuya `placa + fechaEvento horaEvento` aparezca en `Recibidos`; el resto se cuenta como error.
- **Tabla de eventos Tracklog**: 2=Posición, 500=Motor Apagado, 501=Motor Encendido, 540=Botón Pánico, 170=Battery Backup, 135=Aceleración Brusca, 134=Frenado Brusco, 143=Giro Peligroso.
- **Reenvío de historial**: `ReenviarHistorialSatelTrack` (mismo modal que Osinergmin, pasando `service='sateltrack'`).
- **Migración**: `2026_06_20_000000_add_sateltrack_service_to_config_and_devices.php` agrega `sateltrack` al JSON `servicios` de `config` y al JSON `services` de cada `device` existente. **Pendiente de ejecutar** (`php artisan migrate`) cuando MySQL esté disponible.

## Notas / deuda técnica observada

- `SutranSender` tiene métodos muertos (`getConfigModelBySource`, `getDevicesModelBySource`, `getIdFieldBySource`) que referencian modelos `WoxDevices`/`WialonDevices`/`NavixyDevices` que no existen en `app/Models` — parecen de una integración multi-origen no completada.
- `SutranSender::actionAfterSend` hace `Devices::where('imei', ...)->first()->update(...)` sin verificar que el dispositivo exista (posible null) — a diferencia de `OsinergminSender`/`SatelTrackSender` que sí lo verifican.
- `stancl/tenancy` está en `composer.json` pero no hay `config/tenancy.php` ni configuración activa — dependencia no usada actualmente.
- El reenvío automático de historial por brecha de tiempo (`ReenviarHistorial::dispatch` en `Processor.php`) está comentado; el flujo activo es manual desde la UI.
