<?php

declare(strict_types=1);

/**
 * Accesibilidad: botón flotante y panel de preferencias para todo el sitio.
 *
 * El HTML se inyecta automáticamente en cada página completa desde
 * render_vista() y pagina_404(). No usa servicios externos: las preferencias
 * se guardan en localStorage y la lectura en voz alta usa la Web Speech API
 * del navegador.
 *
 * El CSS y el JS viven en public/css/accesibilidad.css y
 * public/js/comun/accesibilidad.js. El script temprano del <head> aplica las
 * preferencias guardadas antes del primer pintado para evitar parpadeos.
 */
final class Accesibilidad
{
    /** Clave de localStorage compartida con accesibilidad.js. */
    private const CLAVE = 'elyra_accesibilidad_v1';

    /**
     * Inserta el CSS, el script temprano y el widget en una página completa.
     * Es idempotente: si el widget ya está presente, no hace nada.
     */
    public static function inyectar(string $html): string
    {
        if (strpos($html, 'id="elyra-accesibilidad"') !== false) {
            return $html;
        }

        $css = '<link href="public/css/accesibilidad.css?v=1" rel="stylesheet">';
        $temprano = self::scriptTemprano();

        if (stripos($html, '</head>') !== false) {
            $html = preg_replace_callback(
                '/<\/head>/i',
                static fn(): string => $css . $temprano . '</head>',
                $html,
                1
            ) ?? $html;
        } else {
            $html = $css . $temprano . $html;
        }

        $widget = self::html()
            . '<script src="public/js/comun/accesibilidad.js?v=1" defer></script>';

        if (stripos($html, '</body>') !== false) {
            $html = preg_replace_callback(
                '/<\/body>/i',
                static fn(): string => $widget . '</body>',
                $html,
                1
            ) ?? $html;
        } else {
            $html .= $widget;
        }

        return $html;
    }

    /** Aplica las preferencias guardadas antes de que se pinte la página. */
    private static function scriptTemprano(): string
    {
        $clave = self::CLAVE;

        return <<<HTML
<script>(function(){try{var s=JSON.parse(localStorage.getItem('{$clave}')||'{}');var r=document.documentElement;var t=parseInt(s.text,10);if(t>0&&t<6){r.setAttribute('data-acc-text',String(t));}if(s.color&&['contrast','invert','gray'].indexOf(s.color)!==-1){r.setAttribute('data-acc-color',s.color);}['spacing','font','links','headings','focus','cursor','motion','images'].forEach(function(k){if(s[k]){r.setAttribute('data-acc-'+k,'1');}});}catch(e){}})();</script>
HTML;
    }

    /** HTML del botón flotante y del panel de opciones. */
    public static function html(): string
    {
        $t = static fn(string $texto): string
            => htmlspecialchars(I18n::t($texto), ENT_QUOTES, 'UTF-8');

        $reemplazos = [
            '{{lang}}' => I18n::esIngles() ? 'en-US' : 'es-UY',
            '{{abrir}}' => $t('Abrir menú de accesibilidad'),
            '{{cerrar}}' => $t('Cerrar menú de accesibilidad'),
            '{{titulo}}' => $t('Accesibilidad'),
            '{{tamano}}' => $t('Tamaño del texto'),
            '{{reducir}}' => $t('Reducir texto'),
            '{{aumentar}}' => $t('Aumentar texto'),
            '{{color}}' => $t('Color'),
            '{{normal}}' => $t('Normal'),
            '{{contraste}}' => $t('Alto contraste'),
            '{{invertir}}' => $t('Invertir colores'),
            '{{grises}}' => $t('Escala de grises'),
            '{{lectura}}' => $t('Lectura'),
            '{{fuente}}' => $t('Fuente de lectura fácil'),
            '{{espaciado}}' => $t('Espaciado de texto'),
            '{{enlaces}}' => $t('Resaltar enlaces'),
            '{{encabezados}}' => $t('Resaltar encabezados'),
            '{{navegacion}}' => $t('Navegación'),
            '{{foco}}' => $t('Resaltar foco'),
            '{{cursor}}' => $t('Cursor grande'),
            '{{guia}}' => $t('Guía de lectura'),
            '{{animaciones}}' => $t('Pausar animaciones'),
            '{{imagenes}}' => $t('Ocultar imágenes'),
            '{{voz}}' => $t('Lectura en voz alta'),
            '{{clic}}' => $t('Lectura al hacer clic'),
            '{{leerPagina}}' => $t('Leer página'),
            '{{leerSeleccion}}' => $t('Leer selección'),
            '{{detener}}' => $t('Detener'),
            '{{vozNoDisponible}}' => $t('Tu navegador no permite la lectura en voz alta.'),
            '{{restablecer}}' => $t('Restablecer'),
            '{{atajo}}' => $t('Atajo: Alt + A'),
            '{{msgReset}}' => $t('Preferencias restablecidas.'),
            '{{msgLeyendo}}' => $t('Leyendo la página…'),
            '{{msgDetenida}}' => $t('Lectura detenida.'),
            '{{msgSeleccion}}' => $t('Seleccioná un texto para leer.'),
            '{{msgVacio}}' => $t('No hay texto para leer.'),
            '{{msgError}}' => $t('No se pudo leer la página.'),
        ];

        return strtr(self::plantilla(), $reemplazos);
    }

    /** Plantilla del widget con marcadores {{...}} reemplazados por textos traducidos. */
    private static function plantilla(): string
    {
        return <<<'HTML'
<div id="elyra-accesibilidad" class="acc" data-lang="{{lang}}"
     data-msg-reset="{{msgReset}}" data-msg-leyendo="{{msgLeyendo}}"
     data-msg-detenida="{{msgDetenida}}" data-msg-seleccion="{{msgSeleccion}}"
     data-msg-vacio="{{msgVacio}}" data-msg-error="{{msgError}}">
    <button type="button" class="acc-boton" id="accBoton"
            aria-expanded="false" aria-controls="accPanel"
            aria-label="{{abrir}}" title="{{abrir}}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="12" cy="4.4" r="2.2" fill="currentColor"></circle>
            <path d="M4 8.6h16M12 8.6V14M12 14l-4 6M12 14l4 6"
                  fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round"></path>
        </svg>
    </button>

    <div class="acc-panel" id="accPanel" role="dialog"
         aria-labelledby="accTitulo" hidden>
        <div class="acc-cabecera">
            <h2 class="acc-titulo" id="accTitulo">{{titulo}}</h2>
            <button type="button" class="acc-cerrar" id="accCerrar"
                    aria-label="{{cerrar}}" title="{{cerrar}}">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor"
                          stroke-width="2" stroke-linecap="round"></path>
                </svg>
            </button>
        </div>

        <div class="acc-cuerpo">
            <section class="acc-seccion" aria-labelledby="accTamanoTitulo">
                <h3 class="acc-subtitulo" id="accTamanoTitulo">{{tamano}}</h3>
                <div class="acc-tamano">
                    <button type="button" class="acc-btn-tamano" data-acc-text-step="-1"
                            aria-label="{{reducir}}">A&minus;</button>
                    <span class="acc-nivel" id="accTextoNivel" aria-live="polite">100%</span>
                    <button type="button" class="acc-btn-tamano" data-acc-text-step="1"
                            aria-label="{{aumentar}}">A+</button>
                </div>
            </section>

            <section class="acc-seccion" aria-labelledby="accColorTitulo">
                <h3 class="acc-subtitulo" id="accColorTitulo">{{color}}</h3>
                <div class="acc-colores" role="group" aria-label="{{color}}">
                    <button type="button" class="acc-color" data-acc-color-option="normal"
                            aria-pressed="true">
                        <span class="acc-muestra acc-muestra-normal" aria-hidden="true"></span>{{normal}}
                    </button>
                    <button type="button" class="acc-color" data-acc-color-option="contrast"
                            aria-pressed="false">
                        <span class="acc-muestra acc-muestra-contraste" aria-hidden="true"></span>{{contraste}}
                    </button>
                    <button type="button" class="acc-color" data-acc-color-option="invert"
                            aria-pressed="false">
                        <span class="acc-muestra acc-muestra-invertir" aria-hidden="true"></span>{{invertir}}
                    </button>
                    <button type="button" class="acc-color" data-acc-color-option="gray"
                            aria-pressed="false">
                        <span class="acc-muestra acc-muestra-grises" aria-hidden="true"></span>{{grises}}
                    </button>
                </div>
            </section>

            <section class="acc-seccion" aria-labelledby="accLecturaTitulo">
                <h3 class="acc-subtitulo" id="accLecturaTitulo">{{lectura}}</h3>
                <button type="button" class="acc-opcion" data-acc-toggle="font" aria-pressed="false">
                    <span>{{fuente}}</span><span class="acc-switch" aria-hidden="true"></span>
                </button>
                <button type="button" class="acc-opcion" data-acc-toggle="spacing" aria-pressed="false">
                    <span>{{espaciado}}</span><span class="acc-switch" aria-hidden="true"></span>
                </button>
                <button type="button" class="acc-opcion" data-acc-toggle="links" aria-pressed="false">
                    <span>{{enlaces}}</span><span class="acc-switch" aria-hidden="true"></span>
                </button>
                <button type="button" class="acc-opcion" data-acc-toggle="headings" aria-pressed="false">
                    <span>{{encabezados}}</span><span class="acc-switch" aria-hidden="true"></span>
                </button>
            </section>

            <section class="acc-seccion" aria-labelledby="accNavegacionTitulo">
                <h3 class="acc-subtitulo" id="accNavegacionTitulo">{{navegacion}}</h3>
                <button type="button" class="acc-opcion" data-acc-toggle="focus" aria-pressed="false">
                    <span>{{foco}}</span><span class="acc-switch" aria-hidden="true"></span>
                </button>
                <button type="button" class="acc-opcion" data-acc-toggle="cursor" aria-pressed="false">
                    <span>{{cursor}}</span><span class="acc-switch" aria-hidden="true"></span>
                </button>
                <button type="button" class="acc-opcion" data-acc-toggle="guide" aria-pressed="false">
                    <span>{{guia}}</span><span class="acc-switch" aria-hidden="true"></span>
                </button>
                <button type="button" class="acc-opcion" data-acc-toggle="motion" aria-pressed="false">
                    <span>{{animaciones}}</span><span class="acc-switch" aria-hidden="true"></span>
                </button>
                <button type="button" class="acc-opcion" data-acc-toggle="images" aria-pressed="false">
                    <span>{{imagenes}}</span><span class="acc-switch" aria-hidden="true"></span>
                </button>
            </section>

            <section class="acc-seccion" aria-labelledby="accVozTitulo">
                <h3 class="acc-subtitulo" id="accVozTitulo">{{voz}}</h3>
                <button type="button" class="acc-opcion" data-acc-toggle="clickRead" aria-pressed="false">
                    <span>{{clic}}</span><span class="acc-switch" aria-hidden="true"></span>
                </button>
                <div class="acc-acciones">
                    <button type="button" class="acc-accion acc-accion-principal" id="accLeerPagina">{{leerPagina}}</button>
                    <button type="button" class="acc-accion" id="accLeerSeleccion">{{leerSeleccion}}</button>
                    <button type="button" class="acc-accion" id="accDetener">{{detener}}</button>
                </div>
                <p class="acc-nota" id="accVozNota" hidden>{{vozNoDisponible}}</p>
            </section>
        </div>

        <p class="acc-estado" id="accEstado" role="status" aria-live="polite"></p>

        <div class="acc-pie">
            <button type="button" class="acc-reset" id="accReset">{{restablecer}}</button>
            <span class="acc-atajo">{{atajo}}</span>
        </div>
    </div>

    <div class="acc-guia" id="accGuia" hidden></div>
</div>
HTML;
    }
}
