using Microsoft.Web.WebView2.WinForms;
using System.Diagnostics;
using System.Net;
using System.Net.Sockets;

namespace AllocatorDesktop;

public partial class Form1 : Form
{
    private Process? _phpProcess;
    private readonly WebView2 _webView = new();
    private int _phpPort;

    public Form1()
    {
        InitializeComponent();
        Text = "K-one Allocator";
        Width = 1200;
        Height = 800;
        StartPosition = FormStartPosition.CenterScreen;

        _webView.Dock = DockStyle.Fill;
        Controls.Add(_webView);

        Load += async (s, e) => await StartAppAsync();
        FormClosing += (s, e) => Cleanup();
    }

    private static int FindAvailablePort()
    {
        var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        int port = ((IPEndPoint)listener.LocalEndpoint).Port;
        listener.Stop();
        return port;
    }

    private async Task StartAppAsync()
    {
        string basePath = AppDomain.CurrentDomain.BaseDirectory;
        string phpExe = Path.Combine(basePath, "php", "php.exe");
        string wwwPath = Path.Combine(basePath, "www");

        if (!File.Exists(phpExe))
        {
            MessageBox.Show(
                $"PHP binary not found at:\n{phpExe}\n\nPlease ensure the php/ folder is next to the executable.",
                "K-one Allocator",
                MessageBoxButtons.OK,
                MessageBoxIcon.Error);
            Close();
            return;
        }

        if (!Directory.Exists(wwwPath))
        {
            MessageBox.Show(
                $"Web content not found at:\n{wwwPath}\n\nPlease ensure the www/ folder is next to the executable.",
                "K-one Allocator",
                MessageBoxButtons.OK,
                MessageBoxIcon.Error);
            Close();
            return;
        }

        _phpPort = FindAvailablePort();

        _phpProcess = Process.Start(new ProcessStartInfo
        {
            FileName = phpExe,
            Arguments = $"-S 127.0.0.1:{_phpPort} -t \"{wwwPath}\"",
            UseShellExecute = false,
            CreateNoWindow = true,
            WindowStyle = ProcessWindowStyle.Hidden,
        });

        // Wait for PHP server to be ready
        bool ready = await WaitForPortAsync(_phpPort, TimeSpan.FromSeconds(5));
        if (!ready)
        {
            MessageBox.Show(
                $"PHP server failed to start on port {_phpPort}.",
                "K-one Allocator",
                MessageBoxButtons.OK,
                MessageBoxIcon.Error);
            Cleanup();
            Close();
            return;
        }

        await _webView.EnsureCoreWebView2Async();
        _webView.CoreWebView2.Navigate($"http://127.0.0.1:{_phpPort}/index.php");
    }

    private static async Task<bool> WaitForPortAsync(int port, TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                using var client = new TcpClient();
                await client.ConnectAsync(IPAddress.Loopback, port);
                return true;
            }
            catch
            {
                await Task.Delay(100);
            }
        }
        return false;
    }

    private void Cleanup()
    {
        if (_phpProcess != null && !_phpProcess.HasExited)
        {
            try { _phpProcess.Kill(); } catch { }
            _phpProcess.Dispose();
            _phpProcess = null;
        }
    }
}
