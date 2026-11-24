' Abre el Gestor de Copias sin consola ni Visual Studio.
' Si el servidor no esta corriendo, arranca iniciar.bat escondido; luego abre la pagina en el navegador.
Option Explicit

Const URL_GESTOR = "http://localhost:8473/"

Dim shell, fso, carpeta, i
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
carpeta = fso.GetParentFolderName(WScript.ScriptFullName)

If Not Responde() Then
    shell.CurrentDirectory = carpeta
    ' 0 = sin ventana; False = no espera a que termine (el servidor queda corriendo)
    shell.Run "cmd /c """ & carpeta & "\iniciar.bat""", 0, False
    ' Espera hasta 10 segundos a que el servidor responda
    For i = 1 To 40
        WScript.Sleep 250
        If Responde() Then Exit For
    Next
    If Not Responde() Then
        MsgBox "No se pudo arrancar el Gestor de Copias." & vbCrLf & _
               "Abre iniciar.bat para ver el error en la consola.", vbExclamation, "Gestor de Copias"
        WScript.Quit 1
    End If
End If

' Se abre siempre en Chrome; si no se encuentra, en el navegador por defecto
Dim chrome
chrome = RutaChrome()
Dim abierto
abierto = False
If chrome <> "" Then
    ' Se usa ShellExecute porque el antivirus bloquea shell.Run con el navegador y una direccion
    On Error Resume Next
    CreateObject("Shell.Application").ShellExecute chrome, URL_GESTOR, "", "open", 1
    abierto = (Err.Number = 0)
    Err.Clear
    On Error GoTo 0
End If
If Not abierto Then shell.Run URL_GESTOR

' Busca chrome.exe en las carpetas donde se instala normalmente
Function RutaChrome()
    Dim rutas, r
    rutas = Array( _
        shell.ExpandEnvironmentStrings("%ProgramFiles%") & "\Google\Chrome\Application\chrome.exe", _
        shell.ExpandEnvironmentStrings("%ProgramFiles(x86)%") & "\Google\Chrome\Application\chrome.exe", _
        shell.ExpandEnvironmentStrings("%LocalAppData%") & "\Google\Chrome\Application\chrome.exe")
    RutaChrome = ""
    For Each r In rutas
        If fso.FileExists(r) Then
            RutaChrome = r
            Exit Function
        End If
    Next
End Function

' Indica si el servidor del gestor ya contesta
Function Responde()
    Dim http
    Responde = False
    On Error Resume Next
    Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
    http.setTimeouts 1000, 1000, 1000, 1000
    http.Open "GET", URL_GESTOR, False
    http.Send
    If Err.Number = 0 Then Responde = (http.Status = 200)
    On Error GoTo 0
End Function