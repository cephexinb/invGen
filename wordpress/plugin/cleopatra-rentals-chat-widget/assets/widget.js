(function () {
  const config = Object.assign(
    {
      apiBaseUrl: "",
      directChatUrl: "",
      chatEndpoint: "/api/chat",
      title: "Cleopatra Rentals Assistant",
      greeting: "Hi 👋 I can help you find the best listing. Tell me your preferred area, bedrooms, and budget.",
      placeholder: "Ask about a listing or tell me what you need...",
    },
    window.CLEO_WIDGET_CONFIG || {}
  );

  const buildChatUrl = () => {
    if (config.directChatUrl) {
      return config.directChatUrl;
    }

    if (!config.apiBaseUrl) {
      return "/wp-json/cleo-chat/v1/chat";
    }

    const base = String(config.apiBaseUrl).replace(/\/$/, "");
    const endpoint = String(config.chatEndpoint || "/api/chat");
    return `${base}${endpoint.startsWith("/") ? endpoint : `/${endpoint}`}`;
  };

  const chatUrl = buildChatUrl();

  const root = document.createElement("div");
  root.className = "cleo-chat-root";

  root.innerHTML = `
    <button class="cleo-chat-toggle" aria-label="Open support chat">💬</button>
    <div class="cleo-chat-panel" hidden>
      <div class="cleo-chat-header">
        <strong>${config.title}</strong>
        <button class="cleo-close" aria-label="Close chat">✕</button>
      </div>
      <div class="cleo-chat-messages"></div>
      <form class="cleo-chat-input-row">
        <input type="text" class="cleo-chat-input" placeholder="${config.placeholder}" />
        <button type="submit">Send</button>
      </form>
    </div>
  `;

  document.body.appendChild(root);

  const panel = root.querySelector(".cleo-chat-panel");
  const toggle = root.querySelector(".cleo-chat-toggle");
  const closeBtn = root.querySelector(".cleo-close");
  const messages = root.querySelector(".cleo-chat-messages");
  const form = root.querySelector(".cleo-chat-input-row");
  const input = root.querySelector(".cleo-chat-input");

  const addMessage = (text, from = "bot") => {
    const el = document.createElement("div");
    el.className = `cleo-msg cleo-msg-${from}`;
    el.innerText = text;
    messages.appendChild(el);
    messages.scrollTop = messages.scrollHeight;
  };

  const setLoading = (loading) => {
    input.disabled = loading;
    form.querySelector("button[type='submit']").disabled = loading;
  };

  toggle.addEventListener("click", () => {
    panel.hidden = !panel.hidden;
    if (!panel.hidden && messages.childElementCount === 0) {
      addMessage(config.greeting, "bot");
    }
  });

  closeBtn.addEventListener("click", () => {
    panel.hidden = true;
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const message = input.value.trim();
    if (!message) return;

    addMessage(message, "user");
    input.value = "";
    setLoading(true);

    try {
      const res = await fetch(chatUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ message, session_id: "web-visitor" }),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        throw new Error(data.error || "Request failed");
      }

      addMessage(data.reply || "I found some options for you. Could you share more details?", "bot");
    } catch (err) {
      addMessage(
        "I couldn’t contact the listings assistant right now. Please check plugin API settings or try again in a minute.",
        "bot"
      );
      console.error("Cleopatra widget request failed", err);
    } finally {
      setLoading(false);
      input.focus();
    }
  });
})();
