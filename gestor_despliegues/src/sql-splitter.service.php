<?php

/**
 * Divide un script SQL en sentencias, como lo hace el cliente de MySQL.
 * Soporta DELIMITER (procedimientos, funciones, triggers), cadenas con comillas
 * simples, dobles y backticks, y comentarios (-- , #, /* *\/).
 * Los comentarios condicionales /*! ... *\/ se conservan porque el servidor los ejecuta.
 */
class SqlSplitterService
{
    /**
     * @return array Lista de ['sql' => string, 'linea' => int]
     */
    public static function dividir(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
        $sql = str_replace("\r\n", "\n", $sql);

        $largo = strlen($sql);
        $delimitador = ';';
        $buffer = '';
        $linea = 1;
        $lineaInicio = null;
        $inicioLinea = true;
        $sentencias = [];
        $i = 0;

        $agregar = function () use (&$buffer, &$lineaInicio, &$sentencias) {
            $texto = trim($buffer);
            if ($texto !== '') {
                $sentencias[] = ['sql' => $texto, 'linea' => $lineaInicio ?? 1];
            }
            $buffer = '';
            $lineaInicio = null;
        };

        while ($i < $largo) {
            // DELIMITER es un comando del cliente: solo aplica al inicio de una línea
            if ($inicioLinea) {
                $finLinea = strpos($sql, "\n", $i);
                if ($finLinea === false) {
                    $finLinea = $largo;
                }
                $textoLinea = substr($sql, $i, $finLinea - $i);
                if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $textoLinea, $m)) {
                    $agregar();
                    $delimitador = $m[1];
                    $i = $finLinea + 1;
                    $linea++;
                    continue;
                }
                $inicioLinea = false;
            }

            $c = $sql[$i];

            if ($c === "\n") {
                $buffer .= $c;
                $linea++;
                $i++;
                $inicioLinea = true;
                continue;
            }

            // Comentario de línea: "-- " o "#"
            if (($c === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $largo || ctype_space($sql[$i + 2])))
                || $c === '#') {
                $fin = strpos($sql, "\n", $i);
                $i = $fin === false ? $largo : $fin;
                continue;
            }

            // Comentario de bloque
            if ($c === '/' && substr($sql, $i, 2) === '/*') {
                $fin = strpos($sql, '*/', $i + 2);
                $fin = $fin === false ? $largo : $fin + 2;
                $bloque = substr($sql, $i, $fin - $i);
                if (substr($bloque, 0, 3) === '/*!') {
                    if ($lineaInicio === null) {
                        $lineaInicio = $linea;
                    }
                    $buffer .= $bloque;
                } else {
                    $buffer .= ' ';
                }
                $linea += substr_count($bloque, "\n");
                $i = $fin;
                continue;
            }

            // Fin de sentencia
            if (substr($sql, $i, strlen($delimitador)) === $delimitador) {
                $agregar();
                $i += strlen($delimitador);
                continue;
            }

            // Cadenas e identificadores entre comillas
            if ($c === "'" || $c === '"' || $c === '`') {
                $j = $i + 1;
                while ($j < $largo) {
                    $actual = $sql[$j];
                    if ($actual === '\\' && $c !== '`') {
                        $j += 2;
                        continue;
                    }
                    if ($actual === $c) {
                        if ($j + 1 < $largo && $sql[$j + 1] === $c) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $fin = min($j + 1, $largo);
                $texto = substr($sql, $i, $fin - $i);
                if ($lineaInicio === null) {
                    $lineaInicio = $linea;
                }
                $buffer .= $texto;
                $linea += substr_count($texto, "\n");
                $i = $fin;
                continue;
            }

            if ($lineaInicio === null && !ctype_space($c)) {
                $lineaInicio = $linea;
            }
            $buffer .= $c;
            $i++;
        }

        $agregar();
        return $sentencias;
    }
}
