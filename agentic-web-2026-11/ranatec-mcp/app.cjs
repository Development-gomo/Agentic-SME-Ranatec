// Startup file for cPanel "Setup Node.js App" (Phusion Passenger), which loads the startup file with require().
// The server itself is an ES module, so load it with a dynamic import.
import("./dist/index.js").catch((err) => {
  console.error("Failed to start ranatec-mcp:", err);
  process.exit(1);
});
