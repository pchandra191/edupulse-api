test("token endpoint parses standard form-encoded OAuth requests", async () => {
  const registration = await fetch(baseUrl + "/oauth/register", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      client_name: "form-parser-test",
      redirect_uris: ["http://localhost:43211/callback"],
      grant_types: ["authorization_code", "refresh_token"],
      response_types: ["code"],
      token_endpoint_auth_method: "none",
    }),
  });
  assert.equal(registration.status, 201);
  const client = await registration.json();
  const tokenResponse = await fetch(baseUrl + "/oauth/token", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      grant_type: "unsupported_test_grant",
      client_id: client.client_id,
      resource: baseUrl + "/mcp",
    }),
  });
  assert.equal(tokenResponse.status, 400);
  assert.equal((await tokenResponse.json()).error, "unsupported_grant_type");
});
