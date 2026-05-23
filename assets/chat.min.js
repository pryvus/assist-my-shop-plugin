class AmsChat {
    constructor() {
        this.isOpen = false;
        this.sessionId = this.generateSessionId();
        this.messages = [];
        this.messagesContainer = document.getElementById('ams-chat-messages');
        this.init();
    }

    init() {
        this.setGreetingMessage()
        this.createChatWidget();
        this.bindEvents();
        this.loadChatHistory();
    }

    setGreetingMessage() {
        this.greetingMessage = 'Hello! How can I help you today?';
        if (typeof Ams !== 'undefined' && Ams && Ams.assistantName) {
            this.greetingMessage = `Hello! My name is ${Ams.assistantName}! How can I help you today?`
        }
    }

    createChatWidget() {
        const widget = document.getElementById('ams-chat-widget');
        if (widget) {
            widget.style.display = 'block';
        }
    }

    bindEvents() {
        const toggle = document.getElementById('ams-chat-toggle');
        const close = document.getElementById('ams-chat-close');
        const input = document.getElementById('ams-chat-input');
        const send = document.getElementById('ams-chat-send');

        if (toggle) {
            toggle.addEventListener('click', () => this.toggleChat());
        }

        if (close) {
            close.addEventListener('click', () => this.closeChat());
        }

        if (send) {
            send.addEventListener('click', () => this.sendMessage());
        }

        if (input) {
            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.sendMessage();
                }
            });
        }

        if (this.messagesContainer) {
            this.messagesContainer.addEventListener('submit', (e) => {
                const form = e.target;
                if (form && form.classList && form.classList.contains('ams-inline-form')) {
                    e.preventDefault();
                    this.handleInlineFormSubmit(form);
                }
            });
        }
    }

    toggleChat() {
        const container = document.getElementById('ams-chat-container');
        if (container) {
            this.isOpen = !this.isOpen;
            container.style.display = this.isOpen ? 'flex' : 'none';

            if (this.isOpen) {
                if (this.messages.length === 0) {
                    this.addMessage('assistant', this.greetingMessage);
                }

                this.scrollDownMessages();
            }


        }
    }

    scrollDownMessages() {
        this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
    }

    closeChat() {
        const container = document.getElementById('ams-chat-container');
        if (container) {
            this.isOpen = false;
            container.style.display = 'none';
        }
    }

    async sendMessage() {
        const input = document.getElementById('ams-chat-input');
        const message = input.value.trim();

        if (!message) return;

        // Add user message to UI
        this.addMessage('user', message);
        input.value = '';

        // Check if streaming is supported and enabled
        if (this.isStreamingSupported()) {
            this.sendMessageStreaming(message);
        } else {
            this.sendMessageNonStreaming(message);
        }
    }

    isStreamingSupported() {
        // Check if EventSource is supported and if we have a streaming endpoint
        const ams = this.getAmsAjax();
        return (typeof EventSource !== 'undefined') && !!(ams && ams.streaming_enabled);
    }

    getAmsAjax() {
        // Return localized Ams object safely with sensible fallbacks
        if (typeof Ams !== 'undefined' && Ams && Ams.AmsAjax) {
            return Ams.AmsAjax;
        }

        return {
            ajax_url: '/wp-admin/admin-ajax.php',
            nonce: '',
            streaming_enabled: false,
            store_url: window.location.origin,
            cart_url: window.location.origin + '/cart/',
            currency_code: 'USD',
            currency_symbol: '$',
            locale: undefined,
        };
    }

    async sendMessageStreaming(message) {
        // Show streaming typing indicator
        this.showStreamingTyping();

        try {
            const amsAjax = this.getAmsAjax();
            console.log('Starting streaming request to:', amsAjax.ajax_url);

            // Use WordPress AJAX endpoint for streaming to avoid CORS issues
            const params = new URLSearchParams({
                action: 'ams_chat_stream',
                nonce: amsAjax.nonce || '',
                message: message,
                session_id: this.sessionId,
            });

            console.log('Streaming params:', params.toString());

            // Make streaming request through WordPress AJAX
            const response = await fetch(amsAjax.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'text/event-stream',
                },
                body: params,
            });

            console.log('Streaming response status:', response.status, response.statusText);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            // Handle streaming response
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let assistantMessageDiv = null;
            let fullContent = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || ''; // Keep incomplete line in buffer

                for (const line of lines) {
                    if (line.startsWith('data: ')) {
                        try {
                            const data = JSON.parse(line.slice(6));

                            if (data.type === 'session' && data.session_id) {
                                this.sessionId = data.session_id;
                            } else if (data.type === 'token' && data.content) {
                                // Create assistant message div if it doesn't exist
                                if (!assistantMessageDiv) {
                                    this.hideStreamingTyping();
                                    assistantMessageDiv = this.createStreamingMessage();
                                }

                                fullContent += data.content;
                                this.updateStreamingMessage(assistantMessageDiv, fullContent);
                            } else if (data.type === 'done') {
                                // Streaming complete
                                if (assistantMessageDiv) {
                                    this.finalizeStreamingMessage(assistantMessageDiv, data.full_content || fullContent);
                                }
                                break;
                            } else if (data.error) {
                                throw new Error(data.error);
                            }
                        } catch (parseError) {
                            console.warn('Failed to parse SSE data:', line, parseError);
                        }
                    }
                }
            }

        } catch (error) {
            console.error('Streaming error:', error);
            this.hideStreamingTyping();

            // Fallback to non-streaming if streaming fails
            console.log('Falling back to non-streaming mode...');
            this.sendMessageNonStreaming(message);
        }
    }

    async sendMessageNonStreaming(message) {
        // Show typing indicator
        this.showTyping();

        try {
            const amsAjax = this.getAmsAjax();
            const response = await fetch(amsAjax.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'ams_chat',
                    nonce: amsAjax.nonce || '',
                    message: message,
                    session_id: this.sessionId,
                }),
            });

            const data = await response.json();
            this.hideTyping();

            if (data.success) {
                this.addMessage('assistant', {
                    text: data.message_text || data.message || '',
                    products: Array.isArray(data.products) ? data.products : [],
                    orders: Array.isArray(data.orders) ? data.orders : [],
                    form: (data.form && typeof data.form === 'object') ? data.form : null,
                });
                if (data.session_id) {
                    this.sessionId = data.session_id;
                }
            } else {
                this.addMessage('assistant', data.error || 'Sorry, I encountered an error. Please try again.');
            }
        } catch (error) {
            this.hideTyping();
            this.addMessage('assistant', 'Sorry, I\'m having trouble connecting. Please try again later.');
        }
    }

    addMessage(type, content, messageTime = false) {
        if (!this.messagesContainer) return;

        const normalizedContent = this.normalizeMessageContent(type, content);
        const messageDiv = document.createElement('div');
        messageDiv.className = `ams-message ams-message-${type}`;

        const messageContent = document.createElement('div');
        messageContent.className = 'ams-message-content';
        // Render text + product/order cards via the sanitized HTML pipeline.
        // The form, if any, is appended via the DOM API afterwards — DOMPurify
        // strips <form>/<input> aggressively even with ADD_TAGS, so we build the
        // form's nodes directly rather than through innerHTML.
        const rawHtml = this.formatMessageContent(normalizedContent.text, normalizedContent.products, normalizedContent.orders, null);
        messageContent.innerHTML = this.sanitizeWidgetHTML(rawHtml);
        if (normalizedContent.form && typeof normalizedContent.form === 'object') {
            const formEl = this.buildInlineFormElement(normalizedContent.form);
            if (formEl) {
                // Retire any earlier active lookup forms — only the most recent
                // form should be interactive. Older ones get hidden so the user
                // isn't left wondering which of N identical forms to fill in.
                this.retireOlderInlineForms();
                messageContent.appendChild(formEl);
            }
        }

        const messageDate = messageTime ? new Date(messageTime) : new Date();
        const timestamp = document.createElement('div');
        timestamp.className = 'ams-message-timestamp';
        timestamp.textContent = messageDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

        messageDiv.appendChild(messageContent);
        messageDiv.appendChild(timestamp);
        this.messagesContainer.appendChild(messageDiv);

        // Scroll to bottom
        this.scrollDownMessages();

        this.messages.push({
            type,
            content: normalizedContent.raw,
            timestamp: messageDate,
        });
    }

    normalizeMessageContent(type, content) {
        if (type !== 'assistant') {
            return {
                text: typeof content === 'string' ? content : '',
                products: [],
                orders: [],
                form: null,
                raw: content,
            };
        }

        if (content && typeof content === 'object' && !Array.isArray(content)) {
            const form = (content.form && typeof content.form === 'object' && !Array.isArray(content.form))
                ? content.form
                : null;
            return {
                text: typeof content.text === 'string' ? content.text : '',
                products: Array.isArray(content.products) ? content.products : [],
                orders: Array.isArray(content.orders) ? content.orders : [],
                form,
                raw: content,
            };
        }

        return {
            text: typeof content === 'string' ? content : '',
            products: [],
            orders: [],
            form: null,
            raw: content,
        };
    }

    formatMessageContent(content, products = [], orders = [], form = null) {
        if (form && typeof form === 'object' && Array.isArray(form.fields) && form.fields.length > 0) {
            const processedText = this.formatTextContent(content || '');
            const formHtml = this.renderInlineForm(form);
            if (!processedText) {
                return formHtml;
            }
            return processedText + '<br><br>' + formHtml;
        }

        if (Array.isArray(orders) && orders.length > 0) {
            const processedText = this.formatTextContent(content || '');
            const ordersHtml = this.renderOrderCards(orders);
            if (!processedText) {
                return ordersHtml;
            }

            return processedText + '<br><br>' + ordersHtml;
        }

        if (Array.isArray(products) && products.length > 0) {
            const processedText = this.formatTextContent(content || '');
            const productsHtml = this.renderProductCards(products);
            if (!processedText) {
                return productsHtml;
            }

            return processedText + '<br><br>' + productsHtml;
        }

        // Check if content contains HTML product cards/grids (support both
        // plugin-generated `ams-*` and server `woo-ai-*` classes) mixed with text
        const productGridRegex = /<div class="(?:ams|woo-ai)[^\"]*products?-grid"/i;
        const productCardRegex = /<div class="(?:ams|woo-ai)[^\"]*product-card"/i;
        if (productGridRegex.test(content) || productCardRegex.test(content) ||
            (content.includes('<img src=') && content.includes('View'))) {

            // Try to capture the trailing product HTML block (support various class prefixes)
            const htmlMatch = content.match(/(<div class="(?:(?:ams|woo-ai)[^\"]*products?-grid|(?:(?:ams|woo-ai)[^\"]*product-card))">.*?<\/div>)$/si);
            if (htmlMatch) {
                const htmlPart = htmlMatch[1];
                const textPart = content.replace(htmlMatch[0], '').trim();

                // Process the text part normally (with Markdown, line breaks, etc.)
                const processedText = this.formatTextContent(textPart);

                // Combine processed text with sanitized HTML
                return processedText + '<br><br>' + this.sanitizeProductHTML(htmlPart);
            }

            // Fallback: treat entire content as product HTML (sanitize + process
            // markdown inside text nodes)
            return this.sanitizeProductHTML(content);
        }

        // Pure text content - use the dedicated text formatting method
        return this.formatTextContent(content);
    }

    renderProductCards(products) {
        const cards = products
            .map((product) => this.renderProductCard(product))
            .filter(Boolean)
            .join('');

        if (!cards) {
            return '';
        }

        return `<div class="ams-products-grid">${cards}</div>`;
    }

    renderProductCard(product) {
        if (!product || typeof product !== 'object') {
            return '';
        }

        const name = this.escapeHtmlSafely(product.name || 'Unknown Product');
        const imageUrl = this.escapeHtmlAttr(product.image_url || '');
        const productUrl = this.escapeHtmlAttr(product.url || '#');
        const productId = product.id;
        const addToCartHref = product.add_to_cart_url
            || this.buildAddToCartUrl(productId)
            || product.url
            || '#';
        const addToCartUrl = this.escapeHtmlAttr(addToCartHref);
        const price = this.formatProductPrice(product.price);

        const actions = [
            `<a href="${productUrl}" target="_blank" rel="noopener noreferrer">View</a>`,
            `<a href="${addToCartUrl}" target="_blank" rel="noopener noreferrer">Add to Cart</a>`,
        ]
            .filter(Boolean)
            .join('');

        return `
            <div class="ams-product-card">
                <div class="ams-product-header">
                    <div class="ams-product-image">
                        <img src="${imageUrl}" alt="${name}">
                    </div>
                    <div class="ams-product-info">
                        <h4>${name}</h4>
                        ${price ? `<p>${price}</p>` : ''}
                    </div>
                </div>
                <div class="ams-product-actions">${actions}</div>
            </div>
        `;
    }

    handleInlineFormSubmit(formEl) {
        if (!formEl || formEl.dataset.amsSubmitted === '1') {
            return;
        }
        const formId = formEl.getAttribute('data-form-id') || 'inline_form';
        const inputs = formEl.querySelectorAll('input.ams-inline-form-input');
        const values = {};
        let missing = '';

        inputs.forEach((input) => {
            const name = input.getAttribute('name');
            if (!name) {
                return;
            }
            const value = String(input.value || '').trim();
            if (input.hasAttribute('required') && value === '') {
                if (!missing) {
                    missing = input.previousElementSibling && input.previousElementSibling.textContent
                        ? input.previousElementSibling.textContent.replace(/\s*\*\s*$/, '').trim()
                        : name;
                }
            }
            values[name] = value;
        });

        const errorEl = formEl.querySelector('.ams-inline-form-error');
        if (missing) {
            if (errorEl) {
                errorEl.textContent = `${missing} is required`;
            }
            return;
        }
        if (errorEl) {
            errorEl.textContent = '';
        }

        formEl.dataset.amsSubmitted = '1';
        const submitBtn = formEl.querySelector('.ams-inline-form-submit');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Looking up…';
        }
        inputs.forEach((input) => { input.disabled = true; });

        // IMPORTANT: never put the email or order number in the user-visible echo
        // or the message field. OrderLookupExtractor scans the last 6 user
        // messages for credentials when the current message lacks them, so any
        // creds leaked into the transcript would be picked up automatically by
        // every later order question — even after the user wants to retry with
        // different details. Form values travel exclusively through
        // lookup_form_response.
        const userEcho = 'Submitted order lookup form';
        this.addMessage('user', userEcho);

        this.sendLookupFormResponse(formId, values);
    }

    async sendLookupFormResponse(formId, values) {
        this.showTyping();
        try {
            const amsAjax = this.getAmsAjax();
            const params = new URLSearchParams({
                action: 'ams_chat',
                nonce: amsAjax.nonce || '',
                message: 'Submitted order lookup form',
                session_id: this.sessionId,
            });
            params.append('lookup_form_response[form_id]', formId);
            Object.keys(values).forEach((key) => {
                params.append(`lookup_form_response[values][${key}]`, String(values[key]));
            });

            const response = await fetch(amsAjax.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params,
            });

            const data = await response.json();
            this.hideTyping();

            if (data.success) {
                this.addMessage('assistant', {
                    text: data.message_text || data.message || '',
                    products: Array.isArray(data.products) ? data.products : [],
                    orders: Array.isArray(data.orders) ? data.orders : [],
                    form: (data.form && typeof data.form === 'object') ? data.form : null,
                });
                if (data.session_id) {
                    this.sessionId = data.session_id;
                }
            } else {
                this.addMessage('assistant', data.error || 'Sorry, I encountered an error. Please try again.');
            }
        } catch (error) {
            this.hideTyping();
            this.addMessage('assistant', 'Sorry, I\'m having trouble connecting. Please try again later.');
        }
    }

    retireOlderInlineForms() {
        if (!this.messagesContainer) {
            return;
        }
        const existing = this.messagesContainer.querySelectorAll('.ams-inline-form:not(.ams-inline-form-retired)');
        existing.forEach((formEl) => {
            formEl.classList.add('ams-inline-form-retired');
            formEl.querySelectorAll('input, button').forEach((el) => { el.disabled = true; });
        });
    }

    buildInlineFormElement(form) {
        if (!form || typeof form !== 'object' || !Array.isArray(form.fields) || form.fields.length === 0) {
            return null;
        }
        const formId = typeof form.id === 'string' && form.id ? form.id : 'inline_form';
        const submitLabel = typeof form.submit_label === 'string' && form.submit_label ? form.submit_label : 'Submit';

        const formEl = document.createElement('form');
        formEl.className = 'ams-inline-form';
        formEl.setAttribute('data-form-id', formId);

        form.fields.forEach((field) => {
            if (!field || typeof field !== 'object') {
                return;
            }
            const name = typeof field.name === 'string' ? field.name : '';
            if (!name) {
                return;
            }
            const fieldLabel = typeof field.label === 'string' ? field.label : name;
            const fieldType = typeof field.type === 'string' ? field.type : 'text';
            const required = field.required === true;
            const fieldId = ('ams-form-' + formId + '-' + name).replace(/[^a-zA-Z0-9_-]/g, '-');

            const wrapper = document.createElement('div');
            wrapper.className = 'ams-inline-form-field';

            const label = document.createElement('label');
            label.className = 'ams-inline-form-label';
            label.setAttribute('for', fieldId);
            label.textContent = fieldLabel + (required ? ' *' : '');

            const input = document.createElement('input');
            input.className = 'ams-inline-form-input';
            input.id = fieldId;
            input.type = fieldType;
            input.name = name;
            input.autocomplete = 'off';
            if (required) {
                input.required = true;
            }

            wrapper.appendChild(label);
            wrapper.appendChild(input);
            formEl.appendChild(wrapper);
        });

        const actions = document.createElement('div');
        actions.className = 'ams-inline-form-actions';
        const submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.className = 'ams-inline-form-submit';
        submitBtn.textContent = submitLabel;
        actions.appendChild(submitBtn);
        formEl.appendChild(actions);

        const errorEl = document.createElement('div');
        errorEl.className = 'ams-inline-form-error';
        errorEl.setAttribute('role', 'alert');
        errorEl.setAttribute('aria-live', 'polite');
        formEl.appendChild(errorEl);

        return formEl;
    }

    renderInlineForm(form) {
        if (!form || typeof form !== 'object') {
            return '';
        }
        const formId = this.escapeHtmlAttr(typeof form.id === 'string' ? form.id : 'inline_form');
        const submitLabel = this.escapeHtmlSafely(typeof form.submit_label === 'string' && form.submit_label ? form.submit_label : 'Submit');

        const fields = Array.isArray(form.fields) ? form.fields : [];
        const fieldsHtml = fields
            .map((field) => {
                if (!field || typeof field !== 'object') {
                    return '';
                }
                const name = this.escapeHtmlAttr(typeof field.name === 'string' ? field.name : '');
                if (!name) {
                    return '';
                }
                const label = this.escapeHtmlSafely(typeof field.label === 'string' ? field.label : name);
                const type = this.escapeHtmlAttr(typeof field.type === 'string' ? field.type : 'text');
                const required = field.required ? 'required' : '';
                const fieldId = `ams-form-${formId}-${name}`.replace(/[^a-zA-Z0-9_-]/g, '-');
                return `
                    <div class="ams-inline-form-field">
                        <label class="ams-inline-form-label" for="${fieldId}">${label}${field.required ? ' *' : ''}</label>
                        <input class="ams-inline-form-input" id="${fieldId}" type="${type}" name="${name}" ${required} autocomplete="off">
                    </div>
                `;
            })
            .filter(Boolean)
            .join('');

        if (!fieldsHtml) {
            return '';
        }

        return `
            <form class="ams-inline-form" data-form-id="${formId}">
                ${fieldsHtml}
                <div class="ams-inline-form-actions">
                    <button type="submit" class="ams-inline-form-submit">${submitLabel}</button>
                </div>
                <div class="ams-inline-form-error" role="alert" aria-live="polite"></div>
            </form>
        `;
    }

    renderOrderCards(orders) {
        const cards = orders
            .map((order) => this.renderOrderCard(order))
            .filter(Boolean)
            .join('');

        if (!cards) {
            return '';
        }

        return `<div class="ams-orders-grid">${cards}</div>`;
    }

    renderOrderCard(order) {
        if (!order || typeof order !== 'object') {
            return '';
        }

        const orderNumber = this.escapeHtmlSafely(order.order_number || String(order.id || ''));
        const statusRaw = String(order.status || '').toLowerCase();
        const statusLabel = this.escapeHtmlSafely(this.formatOrderStatusLabel(statusRaw));
        const statusClass = `ams-order-status-${statusRaw.replace(/[^a-z0-9_-]/g, '-')}`;
        const dateText = this.escapeHtmlSafely(this.formatOrderDate(order.order_date));
        const totalText = this.escapeHtmlSafely(this.formatOrderTotal(order.total, order.currency));
        const viewUrl = this.escapeHtmlAttr(order.view_url || '#');

        const items = Array.isArray(order.line_items) ? order.line_items : [];
        const itemsHtml = items
            .map((item) => {
                if (!item || typeof item !== 'object') {
                    return '';
                }
                const name = this.escapeHtmlSafely(item.name || 'item');
                const quantity = Number(item.quantity);
                const qtyLabel = Number.isFinite(quantity) && quantity > 0 ? quantity : 1;
                return `<li class="ams-order-line-item"><span class="ams-order-line-qty">${qtyLabel}×</span> ${name}</li>`;
            })
            .filter(Boolean)
            .join('');

        const viewAction = viewUrl && viewUrl !== '#'
            ? `<a class="ams-order-view-link" href="${viewUrl}" target="_blank" rel="noopener noreferrer">View order →</a>`
            : '';

        return `
            <div class="ams-order-card">
                <div class="ams-order-header">
                    <span class="ams-order-number">Order #${orderNumber}</span>
                    <span class="ams-order-status-pill ${statusClass}">${statusLabel}</span>
                </div>
                ${dateText ? `<div class="ams-order-date">Placed ${dateText}</div>` : ''}
                ${itemsHtml ? `<ul class="ams-order-items">${itemsHtml}</ul>` : ''}
                <div class="ams-order-footer">
                    ${totalText ? `<span class="ams-order-total">${totalText}</span>` : '<span></span>'}
                    ${viewAction}
                </div>
            </div>
        `;
    }

    formatOrderStatusLabel(status) {
        if (!status) {
            return '';
        }
        return status
            .split(/[-_\s]+/)
            .filter(Boolean)
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' ');
    }

    formatOrderDate(value) {
        if (!value || typeof value !== 'string') {
            return '';
        }
        const date = new Date(value);
        if (isNaN(date.getTime())) {
            return value;
        }
        try {
            return new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
            }).format(date);
        } catch (e) {
            return value;
        }
    }

    formatOrderTotal(total, currency) {
        const numericTotal = Number(total);
        if (!Number.isFinite(numericTotal)) {
            return '';
        }

        const currencyCode = String(currency || '').toUpperCase() || 'USD';
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: currencyCode,
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(numericTotal);
        } catch (e) {
            return `${numericTotal.toFixed(2)} ${currencyCode}`;
        }
    }

    buildAddToCartUrl(productId) {
        if (!productId || !Number.isFinite(Number(productId))) {
            return '';
        }

        const amsAjax = this.getAmsAjax();
        const base = String(amsAjax.cart_url || '').trim();
        if (!base) {
            return '';
        }

        const separator = base.includes('?') ? '&' : '?';
        return `${base}${separator}add-to-cart=${Number(productId)}`;
    }

    formatProductPrice(price) {
        const numericPrice = Number(price);
        if (!Number.isFinite(numericPrice)) {
            return '';
        }

        const amsAjax = this.getAmsAjax();
        const currencyCode = amsAjax.currency_code || 'USD';
        const locale = amsAjax.locale || undefined;

        try {
            return new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: currencyCode,
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(numericPrice);
        } catch (e) {
            const symbol = amsAjax.currency_symbol || '$';
            return `${symbol}${numericPrice.toFixed(2)}`;
        }
    }

    escapeHtmlAttr(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    formatTextContent(content) {
        // This method handles pure text content (no mixed HTML)

        // First, handle literal \n characters (convert them to actual newlines)
        content = content.replace(/\\n/g, '\n');
        // Process content safely by escaping HTML first, then applying Markdown
        let processedContent = this.escapeHtmlSafely(content);
        // Apply markdown formatting
        processedContent = this.parseMarkdown(processedContent);
        // Replace URLs with clickable links
        processedContent = this.convertMarkdownLinksToHtml(processedContent);
        // Convert line breaks to <br> tags
        processedContent = processedContent.replace(/\n/g, '<br>');

        return processedContent;
    }

    convertMarkdownLinksToHtml(text) {
        // Replace markdown links [text](url) but skip rendering when URL is empty
        const escapeAttr = (s) => String(s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        return text.replace(/\[([^\]]+)\]\(([^)]*)\)/g, (match, p1, p2) => {
            const linkText = p1 || '';
            const url = (p2 || '').trim();

            // If URL is empty, render only the link text (no <a>)
            if (!url) {
                return linkText;
            }

            // Allow only safe protocols or relative/hash links
            const allowedProtocols = [ 'http:', 'https:', 'mailto:', 'tel:' ];
            try {
                // Try to construct URL relative to current origin
                const parsed = new URL(url, window.location.origin);
                if (!allowedProtocols.includes(parsed.protocol) && !url.startsWith('/') && !url.startsWith('#')) {
                    return linkText;
                }
            } catch (e) {
                // If URL constructor fails, only allow relative or hash or mailto/tel
                if (!(url.startsWith('/') || url.startsWith('#') || url.startsWith('mailto:') || url.startsWith('tel:'))) {
                    return linkText;
                }
            }

            const safeHref = escapeAttr(url);
            const safeText = linkText;
            return `<a target="_blank" rel="noopener noreferrer" class="ams-link" href="${safeHref}">${safeText}</a>`;
        });
    }

    escapeHtmlSafely(text) {
        // Escape HTML but preserve Markdown syntax characters
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    parseMarkdown(text) {
        // Parse common Markdown syntax - work with HTML entities since text is escaped
        let parsed = text;

        // Bold text: **text** or __text__
        // Handle both regular and HTML entity versions more reliably
        // Triple-asterisk (***text***) -> bold+italic
        parsed = parsed.replace(/\*\*\*(.*?)\*\*\*/g, '<strong><em>$1</em></strong>');
        parsed = parsed.replace(/___(.*?)___/g, '<strong><em>$1</em></strong>');
        parsed = parsed.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        parsed = parsed.replace(/__(.*?)__/g, '<strong>$1</strong>');
        parsed = parsed.replace(/&#42;&#42;(.*?)&#42;&#42;/g, '<strong>$1</strong>');
        parsed = parsed.replace(/&#95;&#95;(.*?)&#95;&#95;/g, '<strong>$1</strong>');

        // Italic text: *text* or _text_ (avoid conflicts with bold by processing bold first)
        // Since we already processed ** above, single * should be safe for italic
        parsed = parsed.replace(/\*([^*\s][^*]*?[^*\s]?)\*/g, '<em>$1</em>');
        parsed = parsed.replace(/_([^_\s][^_]*?[^_\s]?)_/g, '<em>$1</em>');

        // HTML entity versions for italic (single entities only)
        parsed = parsed.replace(/&#42;([^&#42;\s][^&#42;]*?[^&#42;\s]?)&#42;/g, '<em>$1</em>');
        parsed = parsed.replace(/&#95;([^&#95;\s][^&#95;]*?[^&#95;\s]?)&#95;/g, '<em>$1</em>');

        // Inline code: `code`
        parsed = parsed.replace(/`([^`]+?)`/g, '<code class="ams-inline-code">$1</code>');
        parsed = parsed.replace(/&#96;([^&#96;]+?)&#96;/g, '<code class="ams-inline-code">$1</code>');

        // Strikethrough: ~~text~~
        parsed = parsed.replace(/~~(.*?)~~/g, '<del>$1</del>');
        parsed = parsed.replace(/&#126;&#126;(.*?)&#126;&#126;/g, '<del>$1</del>');

        // Headers: # Header (only at start of line or after <br>)
        parsed = parsed.replace(/(^|<br>)### (.*?)(<br>|$)/g, '$1<h3 class="ams-header">$2</h3>$3');
        parsed = parsed.replace(/(^|<br>)## (.*?)(<br>|$)/g, '$1<h2 class="ams-header">$2</h2>$3');
        parsed = parsed.replace(/(^|<br>)# (.*?)(<br>|$)/g, '$1<h1 class="ams-header">$2</h1>$3');

        // HTML entity versions
        parsed = parsed.replace(/(^|<br>)&#35;&#35;&#35; (.*?)(<br>|$)/g, '$1<h3 class="ams-header">$2</h3>$3');
        parsed = parsed.replace(/(^|<br>)&#35;&#35; (.*?)(<br>|$)/g, '$1<h2 class="ams-header">$2</h2>$3');
        parsed = parsed.replace(/(^|<br>)&#35; (.*?)(<br>|$)/g, '$1<h1 class="ams-header">$2</h1>$3');

        // Unordered lists: - item or * item (at start of line or after <br>)
        parsed = parsed.replace(/(^|<br>)[-*] (.*?)(<br>|$)/g, '$1<li class="ams-list-item">$2</li>$3');
        parsed = parsed.replace(/(^|<br>)&#45; (.*?)(<br>|$)/g, '$1<li class="ams-list-item">$2</li>$3');
        parsed = parsed.replace(/(^|<br>)&#42; (.*?)(<br>|$)/g, '$1<li class="ams-list-item">$2</li>$3');

        // Wrap consecutive list items in <ul>
        parsed = parsed.replace(/(<li class="ams-list-item">.*?<\/li>(?:<br>)?)+/g, (match) => {
            return '<ul class="ams-list">' + match.replace(/<br>/g, '') + '</ul>';
        });

        return parsed;
    }

    sanitizeWidgetHTML(rawHtml) {
        if (typeof DOMPurify === 'undefined') {
            return rawHtml;
        }
        // Explicitly allow the form-related tags/attributes our inline-form renderer
        // emits. DOMPurify's default profile is conservative around <form>/<input>,
        // so without ADD_TAGS/ADD_ATTR the lookup form gets stripped to bare labels.
        return DOMPurify.sanitize(rawHtml, {
            ADD_TAGS: ['form'],
            ADD_ATTR: [
                'data-form-id',
                'autocomplete',
                'required',
                'name',
                'type',
                'role',
                'aria-live',
                'for',
            ],
        });
    }

    sanitizeProductHTML(html) {
        // Create a temporary div to parse HTML
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;

        // Allowed tags and attributes for product cards
        const allowedTags = ['div', 'img', 'h3', 'h4', 'p', 'a'];
        const allowedAttributes = {
            'div': ['class', 'style'],
            'img': ['src', 'alt', 'style'],
            'h3': ['style'],
            'h4': ['style'],
            'p': ['style'],
            'a': ['href', 'target', 'rel', 'style']
        };

        // Function to sanitize an element
        const sanitizeElement = (element) => {
            const tagName = element.tagName.toLowerCase();

            // Remove if not allowed tag
            if (!allowedTags.includes(tagName)) {
                element.remove();
                return;
            }

            // Remove disallowed attributes
            const allowedAttrs = allowedAttributes[tagName] || [];
            const attrs = Array.from(element.attributes);
            attrs.forEach(attr => {
                if (!allowedAttrs.includes(attr.name)) {
                    element.removeAttribute(attr.name);
                }
            });

            // Sanitize children
            Array.from(element.children).forEach(child => {
                sanitizeElement(child);
            });
        };

        // Sanitize all elements
        Array.from(tempDiv.children).forEach(child => {
            sanitizeElement(child);
        });

        // After sanitizing tags and attributes, process any remaining Markdown
        // inside text nodes (convert **bold**, *italic*, etc.) while keeping
        // HTML structure intact.
        const walk = document.createTreeWalker(tempDiv, NodeFilter.SHOW_TEXT, null, false);
        const textNodes = [];
        while (walk.nextNode()) {
            textNodes.push(walk.currentNode);
        }

        textNodes.forEach(node => {
            const txt = node.nodeValue;
            if (!txt || !/[*_~`]/.test(txt)) return; // quick check

            // Escape HTML entities then parse markdown to HTML
            const escaped = this.escapeHtmlSafely(txt);
            const parsed = this.parseMarkdown(escaped);

            // If parsing produced HTML different from escaped text, replace the
            // text node with the parsed HTML fragment.
            if (parsed !== escaped) {
                const frag = document.createRange().createContextualFragment(parsed);
                node.parentNode.replaceChild(frag, node);
            }
        });

        return tempDiv.innerHTML;
    }

    showTyping() {
        if (!this.messagesContainer) return;

        const typingDiv = document.createElement('div');
        typingDiv.id = 'ams-typing';
        typingDiv.className = 'ams-message ams-message-assistant';
        typingDiv.innerHTML = `
            <div class="ams-message-content">
                <div class="ams-typing-dots">
                    <span></span><span></span><span></span>
                </div>
            </div>
        `;

        this.messagesContainer.appendChild(typingDiv);
        this.scrollDownMessages()
    }

    hideTyping() {
        const typing = document.getElementById('ams-typing');
        if (typing) {
            typing.remove();
        }
    }

    showStreamingTyping() {
        if (!this.messagesContainer) return;

        const typingDiv = document.createElement('div');
        typingDiv.id = 'ams-streaming-typing';
        typingDiv.className = 'ams-message ams-message-assistant';
        typingDiv.innerHTML = `
            <div class="ams-message-content">
                <div class="ams-streaming-indicator">
                    <span class="ams-streaming-text">AI is thinking</span>
                    <div class="ams-typing-dots">
                        <span></span><span></span><span></span>
                    </div>
                </div>
            </div>
        `;

        this.messagesContainer.appendChild(typingDiv);
        this.scrollDownMessages()
    }

    hideStreamingTyping() {
        const typing = document.getElementById('ams-streaming-typing');
        if (typing) {
            typing.remove();
        }
    }

    createStreamingMessage() {
        if (!this.messagesContainer) return null;

        const messageDiv = document.createElement('div');
        messageDiv.className = 'ams-message ams-message-assistant ams-streaming-message';

        const messageContent = document.createElement('div');
        messageContent.className = 'ams-message-content ams-streaming-content';

        const timestamp = document.createElement('div');
        timestamp.className = 'ams-message-timestamp';
        timestamp.textContent = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

        // Add streaming cursor
        const cursor = document.createElement('span');
        cursor.className = 'ams-streaming-cursor';
        cursor.textContent = '▋';
        messageContent.appendChild(cursor);

        messageDiv.appendChild(messageContent);
        messageDiv.appendChild(timestamp);
        this.messagesContainer.appendChild(messageDiv);

        // Scroll to bottom
        this.scrollDownMessages()

        return messageDiv;
    }

    updateStreamingMessage(messageDiv, content) {
        if (!messageDiv) return;

        const contentDiv = messageDiv.querySelector('.ams-message-content');
        if (!contentDiv) return;

        // Format the content and add the streaming cursor
        const formattedContent = this.formatMessageContent(content);
        const cursor = '<span class="ams-streaming-cursor">▋</span>';
        const combined = formattedContent + cursor;
        contentDiv.innerHTML = (typeof DOMPurify !== 'undefined') ? DOMPurify.sanitize(combined) : combined;

        // Scroll to bottom
        if (this.messagesContainer) {
            this.scrollDownMessages()
        }
    }

    finalizeStreamingMessage(messageDiv, finalContent) {
        if (!messageDiv) return;

        const contentDiv = messageDiv.querySelector('.ams-message-content');
        if (!contentDiv) return;

        // Remove streaming classes and cursor
        messageDiv.classList.remove('ams-streaming-message');
        contentDiv.classList.remove('ams-streaming-content');

        // Set final content without cursor
        const finalHtml = this.formatMessageContent(finalContent);
        contentDiv.innerHTML = (typeof DOMPurify !== 'undefined') ? DOMPurify.sanitize(finalHtml) : finalHtml;

        // Add to messages array
        this.messages.push({
            type: 'assistant',
            content: finalContent,
            timestamp: new Date()
        });

        // Final scroll to bottom
        if (this.messagesContainer) {
            this.scrollDownMessages()
        }
    }

    async loadChatHistory() {
        if (!this.sessionId) return;

        try {
            const amsAjax = this.getAmsAjax();
            const response = await fetch(amsAjax.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'ams_history',
                    nonce: amsAjax.nonce || '',
                    session_id: this.sessionId,
                }),
            });

            // Check if response is ok
            if (!response.ok) {
                console.warn('Chat history request failed:', response.status, response.statusText);
                return;
            }

            // Parse JSON with error handling
            let data;
            let responseText;
            try {
                responseText = await response.text();
                if (!responseText || responseText.trim() === '') {
                    console.warn('Empty response received from chat history request');
                    return;
                }
                data = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Failed to parse chat history response:', parseError);
                console.log('Raw response:', responseText);
                return;
            }

            // Check if data exists and is valid
            if (!data) {
                console.warn('No data received from chat history request');
                return;
            }

            if (data.success && data.messages && data.messages.length > 0) {
                data.messages.forEach(msg => {
                    if (!msg || !msg.type) {
                        return;
                    }

                    const content = msg.type === 'assistant'
                        ? {
                            text: msg.message_text || msg.message || '',
                            products: Array.isArray(msg.products) ? msg.products : [],
                            orders: Array.isArray(msg.orders) ? msg.orders : [],
                            form: (msg.form && typeof msg.form === 'object') ? msg.form : null,
                        }
                        : (msg.message || '');

                    this.addMessage(msg.type, content, msg['created_at']);
                });
            } else if (data.success === false && data.error === 'Conversation not found') {
                // This is normal for new sessions - no error needed
                console.log('No previous conversation found for this session');
            } else if (data.success === false && data.error) {
                console.warn('Chat history error:', data.error);
            }
        } catch (error) {
            console.error('Failed to load chat history:', error);
        }
    }

    generateSessionId() {
        const stored = localStorage.getItem('ams_session_id');
        if (stored) {
            return stored;
        }

        const sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem('ams_session_id', sessionId);
        return sessionId;
    }
}

// Initialize chat when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    new AmsChat();
});
