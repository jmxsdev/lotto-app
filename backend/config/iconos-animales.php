<?php

/**
 * Mapa fuente de verdad slug → icono del catálogo de animalitos
 * (catalogo-juegos R2). Lo consume JuegoCatalogoService::mapearOpcion() para
 * el campo aditivo `icono` de cada opción y la validación de JuegosJsonTest.
 *
 * Reglas de curación (decisión de producto, obs discovery/emoji-cross-platform
 * y decision/catalogo-iconos):
 *  - Emoji Unicode fiel cuando existe (el animal real). Aproximaciones de
 *    industria (Ardilla → 🐿️, Iguana → 🦎, Loro → 🦜, Tortuga → 🐢, Jaguar →
 *    🐆) solo si NO duplican icono dentro del mismo juego.
 *  - Sin emoji fiel → 🐾 (patitas) genérico: 27 sin emoji Unicode alguno
 *    (Cuervo incluido por ser secuencia ZWJ Emoji 15.0, borderline
 *    cross-platform) + los que pierden su aproximación por colisión.
 *  - Regla de colisión: dos opciones del MISMO juego no comparten emoji; gana
 *    el más fiel y el/los otro(s) quedan en 🐾. El mapa es GLOBAL por slug
 *    (R2: slugs duplicados entre juegos comparten entrada única), así que la
 *    resolución de colisiones se aplica al slug completo, no por juego.
 *  - Comodines: `comodin-a` (Leoncito) → 🦁 por decisión explícita de
 *    producto; `comodin-b` (Selva Plus) → 🐾.
 *  - Zodiacal (12 signos) y numérica/terminal (100 números) NO llevan entrada:
 *    su `icono` queda null.
 *
 * Colisiones resueltas (el que pierde va a 🐾):
 *  - cocodrilo 🐊 gana a caiman (el-guacharito y selva-plus tienen ambos).
 *  - cabra 🐐 gana a chivo (el-guacharito y selva-plus).
 *  - buey 🐂 gana a toro (el-guacharito y selva-plus; 🐂 es el buey literal).
 *  - tortuga 🐢 gana a morrocoy (el-guacharito y selva-plus).
 *  - buho 🦉 gana a lechuza (el-guacharito y selva-plus).
 *  - loro 🦜 gana a guacamaya y perico (coexisten en el-guacharito y selva-plus).
 *  - iguana 🦎 gana a camaleon (4 juegos con ambos).
 *  - jaguar 🐆 gana a pantera y puma (4 juegos con los tres).
 *  - comodin-a 🦁 gana a leon (selva-plus contiene ambos; el comodín Leoncito
 *    es el portador explícito del león por decisión de producto).
 */

return [
    'aguila' => '🦅',
    'alacran' => '🦂',
    'anguila' => '🐾',
    'antilope' => '🐾',
    'arana' => '🕷️',
    'ardilla' => '🐿️',
    'avestruz' => '🐾',
    'avispa' => '🐾',
    'ballena' => '🐳',
    'bisonte' => '🦬',
    'buey' => '🐂',
    'bufalo' => '🐃',
    'buho' => '🦉',
    'burro' => '🫏',
    'caballito-de-mar' => '🐾',
    'caballo' => '🐴',
    'cabra' => '🐐',
    'cachicamo' => '🐾',
    'caiman' => '🐾',
    'calamar' => '🦑',
    'camaleon' => '🐾',
    'camaron' => '🦐',
    'camello' => '🐫',
    'canario' => '🐾',
    'cangrejo' => '🦀',
    'canguro' => '🦘',
    'caracol' => '🐌',
    'carnero' => '🐏',
    'cebra' => '🦓',
    'cerdo' => '🐷',
    'chiguire' => '🐾',
    'chivo' => '🐾',
    'ciempies' => '🐾',
    'cisne' => '🦢',
    'cochino' => '🐷',
    'cocodrilo' => '🐊',
    'comodin-a' => '🦁', // Leoncito (comodín A)
    'comodin-b' => '🐾', // Selva Plus (comodín B)
    'conejo' => '🐰',
    'cucaracha' => '🪳',
    'cuervo' => '🐾',
    'culebra' => '🐍',
    'delfin' => '🐬',
    'elefante' => '🐘',
    'erizo-de-mar' => '🐾',
    'escarabajo' => '🪲',
    'gallina' => '🐔',
    'gallo' => '🐓',
    'garza' => '🐾',
    'gato' => '🐱',
    'gavilan' => '🐾',
    'gaviota' => '🐾',
    'gorila' => '🦍',
    'grillo' => '🦗',
    'guacamaya' => '🐾',
    'guacharito' => '🐾',
    'guacharo' => '🐾',
    'halcon' => '🐾',
    'hamster' => '🐹',
    'hipopotamo' => '🦛',
    'hormiga' => '🐜',
    'huron' => '🐾',
    'iguana' => '🦎',
    'jaguar' => '🐆',
    'jirafa' => '🦒',
    'lapa' => '🐾',
    'lechuza' => '🐾',
    'leon' => '🐾',
    'lobo' => '🐺',
    'loro' => '🦜',
    'mariposa' => '🦋',
    'mono' => '🐵',
    'morrocoy' => '🐾',
    'murcielago' => '🦇',
    'oso' => '🐻',
    'oso-hormiguero' => '🐾',
    'paloma' => '🕊️',
    'panda' => '🐼',
    'pantera' => '🐾',
    'pato' => '🦆',
    'patronus' => '🐾',
    'paujil' => '🐾',
    'pavo' => '🦃',
    'pavo-real' => '🦚',
    'pelicano' => '🐾',
    'pereza' => '🦥',
    'perico' => '🐾',
    'perro' => '🐶',
    'pescado' => '🐟',
    'pinguino' => '🐧',
    'puercoespin' => '🐾',
    'pulpo' => '🐙',
    'puma' => '🐾',
    'rana' => '🐸',
    'raton' => '🐭',
    'rinoceronte' => '🦏',
    'tiburon' => '🦈',
    'tigre' => '🐯',
    'toro' => '🐾',
    'tortuga' => '🐢',
    'tucan' => '🐾',
    'turpial' => '🐾',
    'vaca' => '🐮',
    'venado' => '🦌',
    'zamuro' => '🐾',
    'zorro' => '🦊',
];
