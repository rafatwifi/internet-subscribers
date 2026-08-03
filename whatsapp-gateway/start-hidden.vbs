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

' Prevent duplicate gateway (same folder) — optional soft check via port later
LogLine "==== hidden start ===="
LogLine "dir=" & dir
sh.CurrentDirectory = dir

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

' WindowStyle 0 = completely hidden (no black console)
cmd = """" & nodeExe & """ """ & dir & "\index.js"""
LogLine "launch hidden: " & cmd
sh.Run cmd, 0, False
LogLine "Launched OK (fully hidden)"
