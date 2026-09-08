' Runs WhatsApp gateway completely hidden (no console window).
Option Explicit
Dim fso, sh, dir, nodeExe, cmd, logFile

Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")
dir = fso.GetParentFolderName(WScript.ScriptFullName)
logFile = dir & "\autostart.log"

Sub LogLine(msg)
  On Error Resume Next
  Dim tf
  Set tf = fso.OpenTextFile(logFile, 8, True)
  If Not tf Is Nothing Then
    tf.WriteLine Now & "  " & msg
    tf.Close
  End If
  On Error GoTo 0
End Sub

Function FindNode()
  Dim c, line
  c = "C:\Program Files\nodejs\node.exe"
  If fso.FileExists(c) Then FindNode = c : Exit Function
  c = "C:\Program Files (x86)\nodejs\node.exe"
  If fso.FileExists(c) Then FindNode = c : Exit Function
  On Error Resume Next
  line = Trim(sh.Exec("cmd /c where node 2>nul").StdOut.ReadLine())
  On Error GoTo 0
  If line <> "" And fso.FileExists(line) Then FindNode = line : Exit Function
  FindNode = ""
End Function

LogLine "==== hidden start ===="
LogLine "dir=" & dir
sh.CurrentDirectory = dir

' Help AV HTTPS inspection / expired intermediate on some PCs
sh.Environment("Process")("WA_TLS_INSECURE") = "1"
sh.Environment("Process")("PORT") = "3001"

nodeExe = FindNode()
If nodeExe = "" Then
  LogLine "ERROR: node.exe not found"
  WScript.Quit 1
End If
LogLine "node=" & nodeExe

If Not fso.FileExists(dir & "\index.js") Then
  LogLine "ERROR: index.js missing"
  WScript.Quit 1
End If

' Soft-stop previous instance on this port (best-effort)
On Error Resume Next
sh.Run "cmd /c for /f ""tokens=5"" %a in ('netstat -ano ^| findstr :3001 ^| findstr LISTENING') do taskkill /F /PID %a", 0, True
On Error GoTo 0
WScript.Sleep 800

cmd = """" & nodeExe & """ """ & dir & "\index.js"""
LogLine "launch hidden: " & cmd & " WA_TLS_INSECURE=1"
sh.Run cmd, 0, False
LogLine "Launched OK (fully hidden)"
