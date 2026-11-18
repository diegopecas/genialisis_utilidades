' Apaga el servidor del Gestor de Copias (el que arranca abrir-gestor.vbs sin consola).
' Solo cierra el proceso que escucha en el puerto 8473; no toca otros PHP abiertos.
Option Explicit

Const PUERTO = 8473

Dim shell, comando
Set shell = CreateObject("WScript.Shell")

comando = "powershell -NoProfile -ExecutionPolicy Bypass -Command " & _
    """Get-NetTCPConnection -LocalPort " & PUERTO & " -State Listen -ErrorAction SilentlyContinue | " & _
    "Select-Object -ExpandProperty OwningProcess -Unique | " & _
    "ForEach-Object { Stop-Process -Id $_ -Force }"""

' 0 = sin ventana; True = espera a que termine
shell.Run comando, 0, True

MsgBox "El Gestor de Copias quedo apagado.", vbInformation, "Gestor de Copias"
