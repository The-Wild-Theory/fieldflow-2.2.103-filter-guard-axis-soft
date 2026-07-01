using System.Net;
using System.Security.Cryptography;
using System.Text;
using Microsoft.Azure.Functions.Worker;
using Microsoft.Azure.Functions.Worker.Http;

public class LicenseValidateFunction
{
    [Function("LicenseValidate")]
    public async Task<HttpResponseData> Run([HttpTrigger(AuthorizationLevel.Anonymous, "post", Route = "license/validate")] HttpRequestData req)
    {
        string body = await new StreamReader(req.Body).ReadToEndAsync();
        string timestamp = req.Headers.TryGetValues("X-RoutesPro-Time", out var t) ? t.FirstOrDefault() ?? string.Empty : string.Empty;
        string signature = req.Headers.TryGetValues("X-RoutesPro-Signature", out var s) ? s.FirstOrDefault() ?? string.Empty : string.Empty;
        string secret = Environment.GetEnvironmentVariable("ROUTESPRO_LICENSE_SHARED_SECRET") ?? string.Empty;

        using var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(secret));
        string expected = Convert.ToHexString(hmac.ComputeHash(Encoding.UTF8.GetBytes(timestamp + "." + body))).ToLowerInvariant();
        if (!CryptographicOperations.FixedTimeEquals(Encoding.UTF8.GetBytes(expected), Encoding.UTF8.GetBytes(signature.ToLowerInvariant())))
        {
            var denied = req.CreateResponse(HttpStatusCode.Unauthorized);
            await denied.WriteAsJsonAsync(new { success = false, message = "invalid signature" });
            return denied;
        }

        var ok = req.CreateResponse(HttpStatusCode.OK);
        await ok.WriteAsJsonAsync(new
        {
            success = true,
            message = "license validated",
            license = new
            {
                license_id = "lic_demo",
                activation_id = "act_demo",
                status = "active",
                plan = "pro",
                customer = "DEMO",
                domain = "example.com",
                fingerprint = "ABC123DEF456",
                max_activations = 1,
                features = new { ai_tools = true, client_portal = true, premium_branding = true }
            }
        });
        return ok;
    }
}
