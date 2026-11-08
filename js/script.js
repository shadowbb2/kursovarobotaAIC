// mainpage
document.addEventListener('DOMContentLoaded', () => {
    const flashParameters = [
        'added',
        'updated',
        'deleted',
        'bulk_deleted',
        'bulk_blocked',
        'bulk_missing',
        'converted',
    ];
    const currentUrl = new URL(window.location.href);
    let hasFlashParameter = false;
    flashParameters.forEach((parameter) => {
        if (currentUrl.searchParams.has(parameter)) {
            currentUrl.searchParams.delete(parameter);
            hasFlashParameter = true;
        }
    });
    if (hasFlashParameter) {
        window.history.replaceState(window.history.state, '', currentUrl.pathname + currentUrl.search + currentUrl.hash);
    }

    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');

    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('sidebar-open');
        });

        document.addEventListener('click', (event) => {
            const clickedInsideSidebar = sidebar.contains(event.target);
            const clickedMenuButton = menuToggle.contains(event.target);

            if (window.innerWidth <= 900 && !clickedInsideSidebar && !clickedMenuButton) {
                sidebar.classList.remove('sidebar-open');
            }
        });
    }
// worktimeclock
    const workTimeButton = document.getElementById('workTimeButton');
    const workTimeStartLabel = document.getElementById('workTimeStartLabel');
    const workTimeClock = document.getElementById('workTimeClock');
    const workTimeStatus = document.getElementById('workTimeStatus');

    if (workTimeButton && workTimeStartLabel && workTimeClock && workTimeStatus) {
        let startedAt = null;
        let timerInterval = null;
        let statusTimeout = null;
        const csrfToken = workTimeButton.dataset.csrfToken;

        const formatDuration = (totalSeconds) => {
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;
            return [hours, minutes, seconds].map((value) => String(value).padStart(2, '0')).join(':');
        };

        const updateClock = () => {
            if (startedAt === null) {
                return;
            }
            const elapsedSeconds = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
            workTimeClock.textContent = formatDuration(elapsedSeconds);
        };

        const setActiveState = (active, startedAtValue = null) => {
            startedAt = active ? Date.parse(startedAtValue) : null;
            if (active && !Number.isFinite(startedAt)) {
                throw new Error('Сервер повернув некоректний час початку робочої сесії.');
            }

            workTimeButton.classList.toggle('is-active', active);
            workTimeButton.setAttribute('aria-label', active ? 'Завершити роботу' : 'Почати роботу');
            workTimeStartLabel.hidden = active;
            workTimeClock.hidden = !active;

            if (timerInterval !== null) {
                window.clearInterval(timerInterval);
                timerInterval = null;
            }
            if (active) {
                updateClock();
                timerInterval = window.setInterval(updateClock, 1000);
            }
        };

        const requestWorkTime = async (options = {}) => {
            const response = await fetch('work-time.php', {
                credentials: 'same-origin',
                cache: 'no-store',
                ...options,
            });
            let result;
            try {
                result = await response.json();
            } catch (error) {
                throw new Error('Сервер повернув некоректну відповідь для обліку робочого часу.');
            }
            if (!response.ok) {
                throw new Error(result.error || 'Не вдалося виконати дію з робочим часом.');
            }
            return result;
        };

        const setBusy = (busy) => {
            workTimeButton.disabled = busy;
            workTimeButton.classList.toggle('is-loading', busy);
        };

        workTimeButton.addEventListener('click', async () => {
            setBusy(true);
            workTimeStatus.textContent = '';
            if (statusTimeout !== null) {
                window.clearTimeout(statusTimeout);
                statusTimeout = null;
            }
            try {
                const action = startedAt === null ? 'start' : 'stop';
                const body = new URLSearchParams({ action, csrf_token: csrfToken });
                const result = await requestWorkTime({
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body,
                });
                setActiveState(result.active, result.started_at);
                if (action === 'stop') {
                    workTimeStatus.textContent = 'Робочий час завершено та збережено.';
                    statusTimeout = window.setTimeout(() => {
                        workTimeStatus.textContent = '';
                        statusTimeout = null;
                    }, 1000);
                }
            } catch (error) {
                workTimeStatus.textContent = error.message;
                console.error('Помилка обліку робочого часу:', error);
            } finally {
                setBusy(false);
            }
        });

        requestWorkTime()
            .then((result) => {
                setActiveState(result.active, result.started_at);
                workTimeButton.disabled = false;
            })
            .catch((error) => {
                workTimeButton.disabled = true;
                workTimeStatus.textContent = error.message;
                console.error('Не вдалося завантажити стан робочого часу:', error);
            });
    }
//clientform
    const showClientForm = document.getElementById('showClientForm');
    const hideClientForm = document.getElementById('hideClientForm');
    const clientFormPanel = document.getElementById('add-client');

    if (showClientForm && hideClientForm && clientFormPanel) {
        const openClientForm = () => {
            clientFormPanel.hidden = false;
            showClientForm.setAttribute('aria-expanded', 'true');
            clientFormPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            clientFormPanel.querySelector('[name="company_name"]').focus();
        };

        const closeClientForm = () => {
            clientFormPanel.hidden = true;
            showClientForm.setAttribute('aria-expanded', 'false');
            showClientForm.focus();
        };

        showClientForm.addEventListener('click', openClientForm);
        hideClientForm.addEventListener('click', closeClientForm);
    }
//productionform
    const showProductForm = document.getElementById('showProductForm');
    const hideProductForm = document.getElementById('hideProductForm');
    const productFormPanel = document.getElementById('add-product');
    const productAttributeRows = document.getElementById('productAttributeRows');
    const addProductAttribute = document.getElementById('addProductAttribute');
    const productAttributeTemplate = document.getElementById('productAttributeTemplate');

    if (showProductForm && hideProductForm && productFormPanel) {
        const openProductForm = () => {
            productFormPanel.hidden = false;
            showProductForm.setAttribute('aria-expanded', 'true');
            productFormPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            productFormPanel.querySelector('[name="name"]').focus();
        };

        const closeProductForm = () => {
            productFormPanel.hidden = true;
            showProductForm.setAttribute('aria-expanded', 'false');
            showProductForm.focus();
        };

        showProductForm.addEventListener('click', openProductForm);
        hideProductForm.addEventListener('click', closeProductForm);
    }

    const setupProductAttributeEditor = (rows, addButton, template) => {
        if (!rows || !addButton || !template) {
            return;
        }

        const updateAddButton = () => {
            addButton.disabled = rows.querySelectorAll('.product-attribute-row').length >= 30;
        };

        addButton.addEventListener('click', () => {
            if (rows.querySelectorAll('.product-attribute-row').length >= 30) {
                updateAddButton();
                return;
            }
            const row = template.content.firstElementChild.cloneNode(true);
            rows.appendChild(row);
            row.querySelector('[name="attribute_name[]"]').focus();
            updateAddButton();
        });

        rows.addEventListener('click', (event) => {
            const removeButton = event.target.closest('.remove-product-attribute');
            if (!removeButton || !rows.contains(removeButton)) {
                return;
            }
            removeButton.closest('.product-attribute-row').remove();
            updateAddButton();
        });

        updateAddButton();
    };

    setupProductAttributeEditor(productAttributeRows, addProductAttribute, productAttributeTemplate);
    setupProductAttributeEditor(
        document.getElementById('editProductAttributeRows'),
        document.getElementById('addEditProductAttribute'),
        document.getElementById('editProductAttributeTemplate')
    );
//orderform
    const showOrderForm = document.getElementById('showOrderForm');
    const hideOrderForm = document.getElementById('hideOrderForm');
    const orderFormPanel = document.getElementById('add-order');
    if (showOrderForm && hideOrderForm && orderFormPanel) {
        showOrderForm.addEventListener('click', () => {
            orderFormPanel.hidden = false;
            showOrderForm.setAttribute('aria-expanded', 'true');
            orderFormPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            orderFormPanel.querySelector('[name="order_name"]').focus();
        });
        hideOrderForm.addEventListener('click', () => {
            orderFormPanel.hidden = true;
            showOrderForm.setAttribute('aria-expanded', 'false');
            showOrderForm.focus();
        });
    }
//inquiryform
    const showInquiryForm = document.getElementById('showInquiryForm');
    const hideInquiryForm = document.getElementById('hideInquiryForm');
    const inquiryFormPanel = document.getElementById('add-inquiry');
    const inquiryContactType = document.getElementById('inquiryForm');
    const inquiryClientField = document.getElementById('inquiryClientField');
    const inquiryNewContactFields = Array.from(document.querySelectorAll('.inquiry-new-contact-field'));
    if (showInquiryForm && hideInquiryForm && inquiryFormPanel) {
        showInquiryForm.addEventListener('click', () => {
            inquiryFormPanel.hidden = false;
            showInquiryForm.setAttribute('aria-expanded', 'true');
            inquiryFormPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            const selectedType = inquiryFormPanel.querySelector('[name="contact_type"]:checked');
            if (selectedType) {
                selectedType.focus();
            }
        });
        hideInquiryForm.addEventListener('click', () => {
            inquiryFormPanel.hidden = true;
            showInquiryForm.setAttribute('aria-expanded', 'false');
            showInquiryForm.focus();
        });
    }

    if (inquiryContactType && inquiryClientField && inquiryNewContactFields.length > 0) {
        const updateInquiryContactFields = () => {
            const selectedType = inquiryContactType.querySelector('[name="contact_type"]:checked');
            const isClient = selectedType !== null && selectedType.value === 'client';
            inquiryClientField.hidden = !isClient;
            const clientSelect = inquiryClientField.querySelector('[name="client_id"]');
            clientSelect.disabled = !isClient;
            clientSelect.required = isClient;

            inquiryNewContactFields.forEach((field) => {
                field.hidden = isClient;
                const input = field.querySelector('input');
                input.disabled = isClient;
                input.required = !isClient && input.name === 'requester_name';
            });
        };
        inquiryContactType.querySelectorAll('[name="contact_type"]').forEach((input) => {
            input.addEventListener('change', updateInquiryContactFields);
        });
        updateInquiryContactFields();
    }
//inquirybulkdelete
    const inquiryBulkForm = document.getElementById('inquiryBulkForm');
    const startInquiryBulkDelete = document.getElementById('startInquiryBulkDelete');
    const inquiryBulkDeleteButton = document.getElementById('inquiryBulkDeleteButton');
    const cancelInquiryBulkDelete = document.getElementById('cancelInquiryBulkDelete');
    const selectAllInquiries = document.getElementById('selectAllInquiries');
    if (inquiryBulkForm && startInquiryBulkDelete && inquiryBulkDeleteButton
        && cancelInquiryBulkDelete && selectAllInquiries) {
        const inquiryCheckboxes = Array.from(inquiryBulkForm.querySelectorAll('.inquiry-select-checkbox'));
        const setSelectionMode = (enabled) => {
            inquiryBulkForm.classList.toggle('bulk-selection-active', enabled);
            startInquiryBulkDelete.hidden = enabled;
            inquiryBulkDeleteButton.hidden = !enabled;
            cancelInquiryBulkDelete.hidden = !enabled;
        };
        const updateBulkSelection = () => {
            const selectedCount = inquiryCheckboxes.filter((checkbox) => checkbox.checked).length;
            inquiryBulkDeleteButton.disabled = selectedCount === 0;
            inquiryBulkDeleteButton.textContent = selectedCount > 0
                ? `Видалити вибрані (${selectedCount})`
                : 'Видалити вибрані';
            selectAllInquiries.checked = inquiryCheckboxes.length > 0 && selectedCount === inquiryCheckboxes.length;
            selectAllInquiries.indeterminate = selectedCount > 0 && selectedCount < inquiryCheckboxes.length;
        };
        startInquiryBulkDelete.addEventListener('click', () => {
            setSelectionMode(true);
            selectAllInquiries.focus();
        });
        cancelInquiryBulkDelete.addEventListener('click', () => {
            inquiryCheckboxes.forEach((checkbox) => {
                checkbox.checked = false;
            });
            selectAllInquiries.checked = false;
            selectAllInquiries.indeterminate = false;
            updateBulkSelection();
            setSelectionMode(false);
            startInquiryBulkDelete.focus();
        });
        inquiryCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', updateBulkSelection));
        selectAllInquiries.addEventListener('change', () => {
            inquiryCheckboxes.forEach((checkbox) => {
                checkbox.checked = selectAllInquiries.checked;
            });
            updateBulkSelection();
        });
        inquiryBulkForm.addEventListener('submit', (event) => {
            const selectedCount = inquiryCheckboxes.filter((checkbox) => checkbox.checked).length;
            if (selectedCount === 0) {
                event.preventDefault();
                return;
            }
            if (!window.confirm(`Видалити вибрані звернення (${selectedCount})? Цю дію неможливо скасувати.`)) {
                event.preventDefault();
            }
        });
        updateBulkSelection();
    }
//inquirydetails
    const inquiryDetailsDialog = document.getElementById('inquiryDetailsDialog');
    const inquiryDetailsTitle = document.getElementById('inquiryDetailsTitle');
    const inquiryDetailsList = document.getElementById('inquiryDetailsList');
    const inquiryDetailsEdit = document.getElementById('inquiryDetailsEdit');
    const inquiryDetailsActions = document.getElementById('inquiryDetailsActions');
    const inquiryDetailsForm = document.getElementById('inquiryDetailsForm');
    const inquiryClientConversionForm = document.getElementById('inquiryClientConversionForm');
    const inquiryConvertToClient = document.getElementById('inquiryConvertToClient');
    const editInquiryId = document.getElementById('editInquiryId');
    const deleteInquiryId = document.getElementById('deleteInquiryId');
    let activeInquiryRow = null;
    if (inquiryDetailsDialog && inquiryDetailsTitle && inquiryDetailsList && inquiryDetailsEdit
        && inquiryDetailsActions && inquiryDetailsForm && inquiryClientConversionForm
        && inquiryConvertToClient && editInquiryId && deleteInquiryId) {
        const editClientField = document.getElementById('editInquiryClientField');
        const editNewContactFields = Array.from(inquiryDetailsForm.querySelectorAll('.inquiry-new-contact-field'));
        const updateEditContactFields = () => {
            const selectedType = inquiryDetailsForm.querySelector('[name="contact_type"]:checked');
            const isClient = selectedType !== null && selectedType.value === 'client';
            editClientField.hidden = !isClient;
            const clientSelect = editClientField.querySelector('[name="client_id"]');
            clientSelect.disabled = !isClient;
            clientSelect.required = isClient;
            editNewContactFields.forEach((field) => {
                field.hidden = isClient;
                const input = field.querySelector('input');
                input.disabled = isClient;
                input.required = !isClient && input.name === 'requester_name';
            });
        };
        inquiryDetailsForm.querySelectorAll('[name="contact_type"]').forEach((input) => {
            input.addEventListener('change', updateEditContactFields);
        });
        const setEditValues = (inquiry) => {
            editInquiryId.value = inquiry.id || '';
            ['client_id', 'requester_name', 'requester_email', 'requester_phone', 'subject',
                'short_description', 'message', 'source', 'response_due_date', 'status'].forEach((field) => {
                const input = inquiryDetailsForm.elements.namedItem(field);
                if (input) {
                    input.value = inquiry[field] || '';
                }
            });
            const contactType = inquiry.client_id ? 'client' : 'new';
            inquiryDetailsForm.querySelector(`[name="contact_type"][value="${contactType}"]`).checked = true;
            document.getElementById('edit_inquiry_created_at').value = (inquiry.created_at || '').slice(0, 10);
            updateEditContactFields();
        };
        const addInquiryDetail = (label, value, className = '') => {
            const item = document.createElement('div');
            item.className = `client-details-item${className ? ` ${className}` : ''}`;
            const term = document.createElement('dt');
            term.textContent = label;
            const description = document.createElement('dd');
            description.textContent = value || '—';
            item.appendChild(term);
            item.appendChild(description);
            inquiryDetailsList.appendChild(item);
        };
        const addInquiryClientDetail = (inquiry) => {
            const item = document.createElement('div');
            item.className = 'client-details-item';
            const term = document.createElement('dt');
            term.textContent = 'Пов’язаний клієнт';
            const description = document.createElement('dd');
            if (inquiry.client_id && inquiry.client_name) {
                const link = document.createElement('a');
                link.className = 'inquiry-client-link';
                link.href = `clients.php?view=${encodeURIComponent(inquiry.client_id)}`;
                link.textContent = inquiry.client_name;
                description.appendChild(link);
            } else {
                description.textContent = '—';
            }
            item.appendChild(term);
            item.appendChild(description);
            inquiryDetailsList.appendChild(item);
        };
        const showEditMode = () => {
            inquiryDetailsList.hidden = true;
            inquiryDetailsActions.hidden = true;
            inquiryClientConversionForm.hidden = true;
            inquiryDetailsForm.hidden = false;
            inquiryDetailsForm.querySelector('[name="subject"]').focus();
        };
        const showDetailsMode = () => {
            inquiryDetailsForm.hidden = true;
            inquiryClientConversionForm.hidden = true;
            inquiryDetailsList.hidden = false;
            inquiryDetailsActions.hidden = false;
        };
        const showClientConversionMode = () => {
            inquiryDetailsList.hidden = true;
            inquiryDetailsActions.hidden = true;
            inquiryDetailsForm.hidden = true;
            inquiryClientConversionForm.hidden = false;
            inquiryClientConversionForm.querySelector('[name="company_name"]').focus();
        };
        const closeInquiryDetails = () => {
            inquiryDetailsDialog.hidden = true;
            showDetailsMode();
            if (activeInquiryRow) {
                activeInquiryRow.focus();
                activeInquiryRow = null;
            }
        };
        const openInquiryDetails = (row) => {
            let inquiry;
            try {
                inquiry = JSON.parse(row.dataset.inquiryDetails);
            } catch (error) {
                console.error('Не вдалося прочитати дані звернення.', error);
                return null;
            }
            inquiryDetailsTitle.textContent = `${inquiry.inquiry_number || 'Звернення'} · ${inquiry.subject || ''}`;
            deleteInquiryId.value = inquiry.id || '';
            document.getElementById('convertInquiryId').value = inquiry.id || '';
            inquiryConvertToClient.hidden = Boolean(inquiry.client_id);
            if (inquiryDetailsDialog.dataset.reopenConvert !== '1') {
                inquiryClientConversionForm.elements.namedItem('company_name').value = inquiry.requester_name || '';
                inquiryClientConversionForm.elements.namedItem('contact_person').value = inquiry.requester_name || '';
                inquiryClientConversionForm.elements.namedItem('phone').value = inquiry.requester_phone || '';
                inquiryClientConversionForm.elements.namedItem('email').value = inquiry.requester_email || '';
                ['city', 'address', 'notes'].forEach((field) => {
                    inquiryClientConversionForm.elements.namedItem(field).value = '';
                });
            }
            setEditValues(inquiry);
            while (inquiryDetailsList.firstChild) {
                inquiryDetailsList.removeChild(inquiryDetailsList.firstChild);
            }
            addInquiryDetail('Номер звернення', inquiry.inquiry_number);
            addInquiryClientDetail(inquiry);
            [
                ['Контактна особа', inquiry.client_contact_person || inquiry.requester_name],
                ['Телефон', inquiry.client_phone || inquiry.requester_phone],
                ['Електронна пошта', inquiry.client_email || inquiry.requester_email],
                ['Тема', inquiry.subject],
                ['Короткий опис', inquiry.short_description],
                ['Текст запитання', inquiry.message],
                ['Джерело', inquiry.source],
                ['Статус', inquiry.status],
                ['Дата створення', inquiry.created_at],
                ['Відповісти до', inquiry.response_due_date],
                ['Останнє оновлення', inquiry.updated_at],
                ['Запис створив', inquiry.created_by_name],
            ].forEach(([label, value]) => {
                addInquiryDetail(label, value, label === 'Текст запитання' ? 'inquiry-detail-message' : '');
            });
            activeInquiryRow = row;
            inquiryDetailsDialog.hidden = false;
            inquiryDetailsDialog.querySelector('[role="dialog"]').focus();
            showDetailsMode();
            return inquiry;
        };
        document.querySelectorAll('.inquiry-row').forEach((row) => {
            row.addEventListener('click', (event) => {
                if (event.target.closest('a, button, input, label')) {
                    return;
                }
                openInquiryDetails(row);
            });
            row.addEventListener('keydown', (event) => {
                if (event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) {
                    return;
                }
                event.preventDefault();
                openInquiryDetails(row);
            });
        });
        inquiryDetailsEdit.addEventListener('click', showEditMode);
        inquiryConvertToClient.addEventListener('click', showClientConversionMode);
        inquiryClientConversionForm.querySelector('.inquiry-conversion-cancel').addEventListener('click', showDetailsMode);
        inquiryDetailsForm.querySelector('.inquiry-details-cancel-edit').addEventListener('click', showDetailsMode);
        document.getElementById('inquiryDeleteForm').addEventListener('submit', (event) => {
            if (!window.confirm(`Видалити звернення «${inquiryDetailsTitle.textContent}»? Цю дію неможливо скасувати.`)) {
                event.preventDefault();
            }
        });
        inquiryDetailsDialog.querySelectorAll('.inquiry-details-close').forEach((button) => {
            button.addEventListener('click', closeInquiryDetails);
        });
        inquiryDetailsDialog.addEventListener('click', (event) => {
            if (event.target === inquiryDetailsDialog) {
                closeInquiryDetails();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !inquiryDetailsDialog.hidden) {
                closeInquiryDetails();
            }
        });
        const reopenEdit = inquiryDetailsDialog.dataset.reopenEdit === '1';
        const inquiryIdToOpen = reopenEdit
            ? inquiryDetailsDialog.dataset.editInquiryId
            : inquiryDetailsDialog.dataset.viewInquiryId;
        if (inquiryIdToOpen) {
            const row = Array.from(document.querySelectorAll('.inquiry-row')).find((inquiryRow) => {
                try {
                    return JSON.parse(inquiryRow.dataset.inquiryDetails).id === Number(inquiryIdToOpen);
                } catch (error) {
                    console.error('Не вдалося прочитати дані звернення.', error);
                    return false;
                }
            });
            if (row) {
                openInquiryDetails(row);
                if (reopenEdit) {
                    try {
                        const values = JSON.parse(inquiryDetailsDialog.dataset.editValues);
                        setEditValues({ ...values, id: inquiryIdToOpen });
                    } catch (error) {
                        console.error('Не вдалося відновити введені дані звернення.', error);
                    }
                    showEditMode();
                } else if (inquiryDetailsDialog.dataset.reopenConvert === '1') {
                    showClientConversionMode();
                    inquiryDetailsDialog.dataset.reopenConvert = '0';
                }
            }
        }
    }
//ordereditor
    const setupOrderItemsEditor = (rows, addButton, template, total, totalError) => {
        if (!rows || !addButton || !template || !total || !totalError) {
            return null;
        }

        const maximumOrderTotal = 999999999999;
        const formatCents = (cents) => (cents / 100).toLocaleString('uk-UA', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }) + ' ₴';
        const update = () => {
            const itemRows = Array.from(rows.querySelectorAll('.order-item-row'));
            const selectedIds = itemRows
                .map((row) => row.querySelector('[name="machinery_id[]"]').value)
                .filter(Boolean);
            let totalCents = 0;
            let totalExceeded = false;

            itemRows.forEach((row) => {
                const productSelect = row.querySelector('[name="machinery_id[]"]');
                const quantityInput = row.querySelector('[name="item_quantity[]"]');
                const selectedOption = productSelect.selectedOptions[0];
                const price = selectedOption ? selectedOption.dataset.price : '';
                if (price && /^\d+(?:\.\d{1,2})?$/.test(price) && /^\d+$/.test(quantityInput.value)) {
                    const [whole, fraction = ''] = price.split('.');
                    const cents = (Number(whole) * 100) + Number(fraction.padEnd(2, '0'));
                    const lineCents = cents * Number(quantityInput.value);
                    if (lineCents > maximumOrderTotal - totalCents) {
                        totalExceeded = true;
                    } else {
                        totalCents += lineCents;
                    }
                }

                Array.from(productSelect.options).forEach((option) => {
                    if (!option.value || !option.dataset.price) {
                        return;
                    }
                    option.disabled = selectedIds.includes(option.value) && option.value !== productSelect.value;
                });
                row.querySelector('.remove-order-item').disabled = itemRows.length <= 1;
            });

            total.textContent = totalExceeded ? '—' : formatCents(totalCents);
            totalError.hidden = !totalExceeded;
            addButton.disabled = itemRows.length >= 30;
        };

        addButton.addEventListener('click', () => {
            if (rows.querySelectorAll('.order-item-row').length >= 30) {
                update();
                return;
            }
            const row = template.content.firstElementChild.cloneNode(true);
            rows.appendChild(row);
            row.querySelector('[name="machinery_id[]"]').focus();
            update();
        });
        rows.addEventListener('change', update);
        rows.addEventListener('input', update);
        rows.addEventListener('click', (event) => {
            const removeButton = event.target.closest('.remove-order-item');
            if (!removeButton || !rows.contains(removeButton)) {
                return;
            }
            const itemRows = rows.querySelectorAll('.order-item-row');
            if (itemRows.length === 1) {
                itemRows[0].querySelector('[name="machinery_id[]"]').value = '';
                itemRows[0].querySelector('[name="item_quantity[]"]').value = '1';
            } else {
                removeButton.closest('.order-item-row').remove();
            }
            update();
        });
        update();
        return update;
    };

    setupOrderItemsEditor(
        document.getElementById('orderItemRows'),
        document.getElementById('addOrderItem'),
        document.getElementById('orderItemTemplate'),
        document.getElementById('orderTotal'),
        document.getElementById('orderTotalError')
    );
//ordersbulkdelete
    const orderBulkForm = document.getElementById('orderBulkForm');
    const startOrderBulkDelete = document.getElementById('startOrderBulkDelete');
    const orderBulkDeleteButton = document.getElementById('orderBulkDeleteButton');
    const cancelOrderBulkDelete = document.getElementById('cancelOrderBulkDelete');
    const selectAllOrders = document.getElementById('selectAllOrders');
    if (orderBulkForm && startOrderBulkDelete && orderBulkDeleteButton && cancelOrderBulkDelete && selectAllOrders) {
        const orderCheckboxes = Array.from(orderBulkForm.querySelectorAll('.order-select-checkbox'));
        const setSelectionMode = (enabled) => {
            orderBulkForm.classList.toggle('bulk-selection-active', enabled);
            startOrderBulkDelete.hidden = enabled;
            orderBulkDeleteButton.hidden = !enabled;
            cancelOrderBulkDelete.hidden = !enabled;
        };
        const updateSelection = () => {
            const selectedCount = orderCheckboxes.filter((checkbox) => checkbox.checked).length;
            orderBulkDeleteButton.disabled = selectedCount === 0;
            orderBulkDeleteButton.textContent = selectedCount > 0
                ? `Видалити вибрані (${selectedCount})`
                : 'Видалити вибрані';
            selectAllOrders.checked = orderCheckboxes.length > 0 && selectedCount === orderCheckboxes.length;
            selectAllOrders.indeterminate = selectedCount > 0 && selectedCount < orderCheckboxes.length;
        };
        startOrderBulkDelete.addEventListener('click', () => {
            setSelectionMode(true);
            selectAllOrders.focus();
        });
        cancelOrderBulkDelete.addEventListener('click', () => {
            orderCheckboxes.forEach((checkbox) => {
                checkbox.checked = false;
            });
            selectAllOrders.checked = false;
            selectAllOrders.indeterminate = false;
            updateSelection();
            setSelectionMode(false);
            startOrderBulkDelete.focus();
        });
        orderCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', updateSelection));
        selectAllOrders.addEventListener('change', () => {
            orderCheckboxes.forEach((checkbox) => {
                checkbox.checked = selectAllOrders.checked;
            });
            updateSelection();
        });
        orderBulkForm.addEventListener('submit', (event) => {
            const selectedCount = orderCheckboxes.filter((checkbox) => checkbox.checked).length;
            if (selectedCount === 0) {
                event.preventDefault();
                return;
            }
            if (!window.confirm(`Видалити вибрані замовлення (${selectedCount})? Їхні позиції продукції також буде видалено.`)) {
                event.preventDefault();
            }
        });
        updateSelection();
        if (orderCheckboxes.some((checkbox) => checkbox.checked)) {
            setSelectionMode(true);
        }
    }
//orderdetails
    const orderDetailsDialog = document.getElementById('orderDetailsDialog');
    const orderDetailsTitle = document.getElementById('orderDetailsTitle');
    const orderDetailsList = document.getElementById('orderDetailsList');
    const orderDetailItemsBody = document.getElementById('orderDetailItemsBody');
    const orderDetailsEdit = document.getElementById('orderDetailsEdit');
    const orderDetailsActions = document.getElementById('orderDetailsActions');
    const orderDetailsForm = document.getElementById('orderDetailsForm');
    const editOrderId = document.getElementById('editOrderId');
    const deleteOrderId = document.getElementById('deleteOrderId');
    const editOrderItemRows = document.getElementById('editOrderItemRows');
    const editOrderItemTemplate = document.getElementById('editOrderItemTemplate');
    const editOrderItemsUpdate = setupOrderItemsEditor(
        editOrderItemRows,
        document.getElementById('addEditOrderItem'),
        editOrderItemTemplate,
        document.getElementById('editOrderTotal'),
        document.getElementById('editOrderTotalError')
    );
    let activeOrderRow = null;

    if (orderDetailsDialog && orderDetailsTitle && orderDetailsList && orderDetailItemsBody
        && orderDetailsEdit && orderDetailsActions && orderDetailsForm && editOrderId
        && deleteOrderId && editOrderItemRows && editOrderItemTemplate && editOrderItemsUpdate) {
        const editableFields = ['order_name', 'client_id', 'due_date', 'status', 'notes'];
        const formatMoney = (value) => Number(value || 0).toLocaleString('uk-UA', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }) + ' ₴';

        const setEditValues = (order, items = order.items) => {
            editOrderId.value = order.id || '';
            editableFields.forEach((field) => {
                const input = orderDetailsForm.elements.namedItem(field);
                if (input) {
                    input.value = order[field] ?? '';
                }
            });
            orderDetailsForm.querySelector('[name="order_date"]').value = order.order_date || '';

            while (editOrderItemRows.firstChild) {
                editOrderItemRows.removeChild(editOrderItemRows.firstChild);
            }
            const orderItems = Array.isArray(items) && items.length > 0 ? items : [{ machinery_id: '', quantity: '1' }];
            orderItems.forEach((item) => {
                const row = editOrderItemTemplate.content.firstElementChild.cloneNode(true);
                const productSelect = row.querySelector('[name="machinery_id[]"]');
                const quantityInput = row.querySelector('[name="item_quantity[]"]');
                if (item.machinery_id) {
                    const existingOption = Array.from(productSelect.options)
                        .find((option) => option.value === String(item.machinery_id));
                    if (existingOption && existingOption.dataset.price) {
                        productSelect.value = String(item.machinery_id);
                    } else {
                        const unavailable = document.createElement('option');
                        unavailable.value = '';
                        unavailable.textContent = `${item.product_name} — ціна недоступна, оберіть продукт із ціною`;
                        unavailable.selected = true;
                        unavailable.disabled = true;
                        productSelect.appendChild(unavailable);
                    }
                } else if (item.product_name) {
                    const unavailable = document.createElement('option');
                    unavailable.value = '';
                    unavailable.textContent = `${item.product_name} — продукт недоступний, оберіть заміну`;
                    unavailable.selected = true;
                    unavailable.disabled = true;
                    productSelect.appendChild(unavailable);
                }
                quantityInput.value = String(item.quantity || 1);
                editOrderItemRows.appendChild(row);
            });
            orderDetailsForm.querySelector('[name="due_date"]').min = order.order_date || '';
            editOrderItemsUpdate();
        };

        const showEditMode = () => {
            orderDetailsList.hidden = true;
            document.getElementById('orderDetailItems').hidden = true;
            orderDetailsActions.hidden = true;
            orderDetailsForm.hidden = false;
            orderDetailsForm.querySelector('[name="order_name"]').focus();
        };
        const showDetailsMode = () => {
            orderDetailsForm.hidden = true;
            orderDetailsList.hidden = false;
            document.getElementById('orderDetailItems').hidden = false;
            orderDetailsActions.hidden = false;
        };
        const closeOrderDetails = () => {
            orderDetailsDialog.hidden = true;
            showDetailsMode();
            if (activeOrderRow) {
                activeOrderRow.focus();
                activeOrderRow = null;
            }
        };
        const addDetail = (label, value, className = '') => {
            const item = document.createElement('div');
            item.className = `client-details-item${className ? ` ${className}` : ''}`;
            const term = document.createElement('dt');
            term.textContent = label;
            const description = document.createElement('dd');
            description.textContent = value || '—';
            item.appendChild(term);
            item.appendChild(description);
            orderDetailsList.appendChild(item);
        };
        const addLinkedDetail = (label, value, href) => {
            const item = document.createElement('div');
            item.className = 'client-details-item';
            const term = document.createElement('dt');
            term.textContent = label;
            const description = document.createElement('dd');
            if (value && href) {
                const link = document.createElement('a');
                link.className = 'order-related-link';
                link.href = href;
                link.textContent = value;
                description.appendChild(link);
            } else {
                description.textContent = value || '—';
            }
            item.appendChild(term);
            item.appendChild(description);
            orderDetailsList.appendChild(item);
        };

        const openOrderDetails = (row) => {
            let order;
            try {
                order = JSON.parse(row.dataset.orderDetails);
            } catch (error) {
                console.error('Не вдалося прочитати дані замовлення.', error);
                return null;
            }
            orderDetailsTitle.textContent = `${order.order_number || 'Замовлення'} · ${order.order_name || ''}`;
            deleteOrderId.value = order.id || '';
            setEditValues(order);
            while (orderDetailsList.firstChild) {
                orderDetailsList.removeChild(orderDetailsList.firstChild);
            }
            addDetail('Номер замовлення', order.order_number);
            addDetail('Назва', order.order_name);
            addLinkedDetail('Клієнт', order.client_name, order.client_id ? `clients.php?view=${encodeURIComponent(order.client_id)}` : '');
            addDetail('Дата створення', order.order_date);
            addDetail('Заплановане завершення', order.due_date);
            addDetail('Статус', order.status);
            addDetail('Загальна сума', formatMoney(order.total_price));
            addDetail('Коментар', order.notes, 'order-details-notes');

            while (orderDetailItemsBody.firstChild) {
                orderDetailItemsBody.removeChild(orderDetailItemsBody.firstChild);
            }
            (Array.isArray(order.items) ? order.items : []).forEach((product) => {
                const itemRow = document.createElement('tr');
                const productCell = document.createElement('td');
                if (product.machinery_id) {
                    const link = document.createElement('a');
                    link.className = 'order-related-link';
                    link.href = `machinery.php?view=${encodeURIComponent(product.machinery_id)}`;
                    link.textContent = product.product_name || 'Продукт';
                    productCell.appendChild(link);
                } else {
                    productCell.textContent = product.product_name || '—';
                }
                itemRow.appendChild(productCell);
                [String(product.quantity || 0), formatMoney(product.unit_price), formatMoney(product.line_total)]
                    .forEach((value) => {
                        const cell = document.createElement('td');
                        cell.textContent = value;
                        itemRow.appendChild(cell);
                    });
                orderDetailItemsBody.appendChild(itemRow);
            });
            activeOrderRow = row;
            orderDetailsDialog.hidden = false;
            orderDetailsDialog.querySelector('[role="dialog"]').focus();
            showDetailsMode();
            return order;
        };

        document.querySelectorAll('.order-row').forEach((row) => {
            row.addEventListener('click', (event) => {
                if (event.target.closest('a, button, input, label')) {
                    return;
                }
                openOrderDetails(row);
            });
            row.addEventListener('keydown', (event) => {
                if (event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) {
                    return;
                }
                event.preventDefault();
                openOrderDetails(row);
            });
        });
        orderDetailsEdit.addEventListener('click', showEditMode);
        orderDetailsForm.querySelector('.order-details-cancel-edit').addEventListener('click', showDetailsMode);
        document.getElementById('orderDeleteForm').addEventListener('submit', (event) => {
            if (!window.confirm(`Видалити замовлення «${orderDetailsTitle.textContent}»? Цю дію неможливо скасувати.`)) {
                event.preventDefault();
            }
        });
        orderDetailsDialog.querySelectorAll('.order-details-close').forEach((button) => {
            button.addEventListener('click', closeOrderDetails);
        });
        orderDetailsDialog.addEventListener('click', (event) => {
            if (event.target === orderDetailsDialog) {
                closeOrderDetails();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !orderDetailsDialog.hidden) {
                closeOrderDetails();
            }
        });

        const reopenEdit = orderDetailsDialog.dataset.reopenEdit === '1';
        const orderIdToOpen = reopenEdit
            ? orderDetailsDialog.dataset.editOrderId
            : orderDetailsDialog.dataset.viewOrderId;
        if (orderIdToOpen) {
            const row = Array.from(document.querySelectorAll('.order-row')).find((orderRow) => {
                try {
                    return JSON.parse(orderRow.dataset.orderDetails).id === Number(orderIdToOpen);
                } catch (error) {
                    console.error('Не вдалося прочитати дані замовлення.', error);
                    return false;
                }
            });
            if (row) {
                openOrderDetails(row);
                if (reopenEdit) {
                    try {
                        const values = JSON.parse(orderDetailsDialog.dataset.editValues);
                        const items = JSON.parse(orderDetailsDialog.dataset.editItems);
                        setEditValues({ ...values, id: orderIdToOpen }, items);
                    } catch (error) {
                        console.error('Не вдалося відновити введені дані замовлення.', error);
                    }
                    showEditMode();
                }
            }
        }
    }
//productsbulkdelete
    const productBulkForm = document.getElementById('productBulkForm');
    const startProductBulkDelete = document.getElementById('startProductBulkDelete');
    const productBulkDeleteButton = document.getElementById('productBulkDeleteButton');
    const cancelProductBulkDelete = document.getElementById('cancelProductBulkDelete');
    const selectAllProducts = document.getElementById('selectAllProducts');

    if (productBulkForm && startProductBulkDelete && productBulkDeleteButton && cancelProductBulkDelete && selectAllProducts) {
        const productCheckboxes = Array.from(productBulkForm.querySelectorAll('.product-select-checkbox'));
        const setSelectionMode = (enabled) => {
            productBulkForm.classList.toggle('bulk-selection-active', enabled);
            startProductBulkDelete.hidden = enabled;
            productBulkDeleteButton.hidden = !enabled;
            cancelProductBulkDelete.hidden = !enabled;
        };
        const updateBulkSelection = () => {
            const selectedCount = productCheckboxes.filter((checkbox) => checkbox.checked).length;
            productBulkDeleteButton.disabled = selectedCount === 0;
            productBulkDeleteButton.textContent = selectedCount > 0
                ? `Видалити вибрані (${selectedCount})`
                : 'Видалити вибрані';
            selectAllProducts.checked = productCheckboxes.length > 0 && selectedCount === productCheckboxes.length;
            selectAllProducts.indeterminate = selectedCount > 0 && selectedCount < productCheckboxes.length;
        };

        startProductBulkDelete.addEventListener('click', () => {
            setSelectionMode(true);
            selectAllProducts.focus();
        });
        cancelProductBulkDelete.addEventListener('click', () => {
            productCheckboxes.forEach((checkbox) => {
                checkbox.checked = false;
            });
            selectAllProducts.checked = false;
            selectAllProducts.indeterminate = false;
            updateBulkSelection();
            setSelectionMode(false);
            startProductBulkDelete.focus();
        });
        productCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', updateBulkSelection));
        selectAllProducts.addEventListener('change', () => {
            productCheckboxes.forEach((checkbox) => {
                checkbox.checked = selectAllProducts.checked;
            });
            updateBulkSelection();
        });
        productBulkForm.addEventListener('submit', (event) => {
            const selectedCount = productCheckboxes.filter((checkbox) => checkbox.checked).length;
            if (selectedCount === 0) {
                event.preventDefault();
                return;
            }
            if (!window.confirm(`Видалити вибрані продукти (${selectedCount})? Їхні характеристики буде видалено, а пов’язані замовлення залишаться без прив’язки до продукту.`)) {
                event.preventDefault();
            }
        });

        updateBulkSelection();
        if (productCheckboxes.some((checkbox) => checkbox.checked)) {
            setSelectionMode(true);
        }
    }
//productdetails
    const productDetailsDialog = document.getElementById('productDetailsDialog');
    const productDetailsTitle = document.getElementById('productDetailsTitle');
    const productDetailsList = document.getElementById('productDetailsList');
    const productDetailAttributes = document.getElementById('productDetailAttributes');
    const productDetailAttributesBody = document.getElementById('productDetailAttributesBody');
    const productRelatedOrders = document.getElementById('productRelatedOrders');
    const productRelatedOrdersList = document.getElementById('productRelatedOrdersList');
    const productDetailsEdit = document.getElementById('productDetailsEdit');
    const productDetailsActions = document.getElementById('productDetailsActions');
    const productDetailsForm = document.getElementById('productDetailsForm');
    const editProductId = document.getElementById('editProductId');
    const deleteProductId = document.getElementById('deleteProductId');
    const editProductAttributeRows = document.getElementById('editProductAttributeRows');
    const editProductAttributeTemplate = document.getElementById('editProductAttributeTemplate');
    let activeProductRow = null;
    let activeProductHasAttributes = false;

    if (productDetailsDialog && productDetailsTitle && productDetailsList && productDetailAttributes
        && productDetailAttributesBody && productRelatedOrders && productRelatedOrdersList
        && productDetailsEdit && productDetailsActions
        && productDetailsForm && editProductId && deleteProductId && editProductAttributeRows
        && editProductAttributeTemplate) {
        const editableFields = ['name', 'model', 'category', 'price', 'stock_quantity', 'description'];
        const productDetailFields = [
            ['model', 'Модель'],
            ['category', 'Категорія'],
            ['price', 'Ціна'],
            ['stock_quantity', 'Кількість на складі'],
            ['created_at', 'Дата створення'],
            ['updated_at', 'Останнє оновлення'],
            ['description', 'Опис'],
        ];

        const setEditValues = (product, attributes = product.attributes) => {
            editProductId.value = product.id || '';
            editableFields.forEach((field) => {
                const input = productDetailsForm.elements.namedItem(field);
                if (input) {
                    input.value = product[field] ?? '';
                }
            });

            while (editProductAttributeRows.firstChild) {
                editProductAttributeRows.removeChild(editProductAttributeRows.firstChild);
            }
            (Array.isArray(attributes) ? attributes : []).forEach((attribute) => {
                const row = editProductAttributeTemplate.content.firstElementChild.cloneNode(true);
                row.querySelector('[name="attribute_name[]"]').value = attribute.name || '';
                row.querySelector('[name="attribute_value[]"]').value = attribute.value || '';
                editProductAttributeRows.appendChild(row);
            });
            const addButton = document.getElementById('addEditProductAttribute');
            if (addButton) {
                addButton.disabled = editProductAttributeRows.querySelectorAll('.product-attribute-row').length >= 30;
            }
        };

        const showEditMode = () => {
            productDetailsList.hidden = true;
            productDetailAttributes.hidden = true;
            productRelatedOrders.hidden = true;
            productDetailsActions.hidden = true;
            productDetailsForm.hidden = false;
            productDetailsForm.querySelector('[name="name"]').focus();
        };
        const showDetailsMode = () => {
            productDetailsForm.hidden = true;
            productDetailsList.hidden = false;
            productDetailAttributes.hidden = !activeProductHasAttributes;
            productRelatedOrders.hidden = false;
            productDetailsActions.hidden = false;
        };
        const closeProductDetails = () => {
            productDetailsDialog.hidden = true;
            showDetailsMode();
            if (activeProductRow) {
                activeProductRow.focus();
                activeProductRow = null;
            }
        };

        const openProductDetails = (row) => {
            let product;
            try {
                product = JSON.parse(row.dataset.productDetails);
            } catch (error) {
                console.error('Не вдалося прочитати дані продукту.', error);
                return null;
            }

            productDetailsTitle.textContent = product.name || 'Продукт';
            setEditValues(product);
            deleteProductId.value = product.id || '';
            while (productDetailsList.firstChild) {
                productDetailsList.removeChild(productDetailsList.firstChild);
            }
            productDetailFields.forEach(([field, label]) => {
                const item = document.createElement('div');
                item.className = 'client-details-item';
                if (field === 'description') {
                    item.classList.add('product-description-detail');
                }
                const term = document.createElement('dt');
                term.textContent = label;
                const value = document.createElement('dd');
                if (field === 'price') {
                    value.textContent = product.price === null
                        ? '—'
                        : `${Number(product.price).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ₴`;
                } else if (field === 'stock_quantity') {
                    value.textContent = Number(product.stock_quantity).toLocaleString('uk-UA');
                } else {
                    value.textContent = product[field] || '—';
                }
                item.appendChild(term);
                item.appendChild(value);
                productDetailsList.appendChild(item);
            });

            while (productRelatedOrdersList.firstChild) {
                productRelatedOrdersList.removeChild(productRelatedOrdersList.firstChild);
            }
            const relatedOrders = Array.isArray(product.orders) ? product.orders : [];
            if (relatedOrders.length === 0) {
                const emptyItem = document.createElement('li');
                emptyItem.className = 'client-related-empty';
                emptyItem.textContent = 'Замовлень із цим продуктом поки немає.';
                productRelatedOrdersList.appendChild(emptyItem);
            } else {
                relatedOrders.forEach((order) => {
                    const item = document.createElement('li');
                    const link = document.createElement('a');
                    link.href = `orders.php?view=${encodeURIComponent(order.id)}`;
                    link.textContent = `${order.order_number || 'Замовлення'} · ${order.order_name || ''}`;
                    item.appendChild(link);
                    const metadata = document.createElement('span');
                    metadata.textContent = [order.client_name, order.order_date, order.status].filter(Boolean).join(' · ');
                    item.appendChild(metadata);
                    productRelatedOrdersList.appendChild(item);
                });
            }

            while (productDetailAttributesBody.firstChild) {
                productDetailAttributesBody.removeChild(productDetailAttributesBody.firstChild);
            }
            const attributes = Array.isArray(product.attributes) ? product.attributes : [];
            activeProductHasAttributes = attributes.length > 0;
            attributes.forEach((attribute) => {
                const rowElement = document.createElement('tr');
                const nameCell = document.createElement('td');
                const valueCell = document.createElement('td');
                nameCell.textContent = attribute.name || '—';
                valueCell.textContent = attribute.value || '—';
                rowElement.appendChild(nameCell);
                rowElement.appendChild(valueCell);
                productDetailAttributesBody.appendChild(rowElement);
            });

            activeProductRow = row;
            productDetailsDialog.hidden = false;
            productDetailsDialog.querySelector('[role="dialog"]').focus();
            showDetailsMode();
            return product;
        };

        document.querySelectorAll('.product-row').forEach((row) => {
            row.addEventListener('click', (event) => {
                if (event.target.closest('a, button, input, label')) {
                    return;
                }
                openProductDetails(row);
            });
            row.addEventListener('keydown', (event) => {
                if (event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) {
                    return;
                }
                event.preventDefault();
                openProductDetails(row);
            });
        });

        productDetailsEdit.addEventListener('click', showEditMode);
        productDetailsForm.querySelector('.product-details-cancel-edit').addEventListener('click', showDetailsMode);
        document.getElementById('productDeleteForm').addEventListener('submit', (event) => {
            if (!window.confirm(`Видалити продукт «${productDetailsTitle.textContent}»? Його характеристики буде видалено, а пов’язані замовлення залишаться без прив’язки до продукту. Цю дію неможливо скасувати.`)) {
                event.preventDefault();
            }
        });

        productDetailsDialog.querySelectorAll('.product-details-close').forEach((button) => {
            button.addEventListener('click', closeProductDetails);
        });
        productDetailsDialog.addEventListener('click', (event) => {
            if (event.target === productDetailsDialog) {
                closeProductDetails();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !productDetailsDialog.hidden) {
                closeProductDetails();
            }
        });

        const reopenEdit = productDetailsDialog.dataset.reopenEdit === '1';
        const productIdToOpen = reopenEdit
            ? productDetailsDialog.dataset.editProductId
            : productDetailsDialog.dataset.viewProductId;
        if (productIdToOpen) {
            const row = Array.from(document.querySelectorAll('.product-row')).find((productRow) => {
                try {
                    return JSON.parse(productRow.dataset.productDetails).id === Number(productIdToOpen);
                } catch (error) {
                    console.error('Не вдалося прочитати дані продукту.', error);
                    return false;
                }
            });
            if (row) {
                openProductDetails(row);
                if (reopenEdit) {
                    try {
                        const values = JSON.parse(productDetailsDialog.dataset.editValues);
                        const attributes = JSON.parse(productDetailsDialog.dataset.editAttributes);
                        setEditValues({ ...values, id: productIdToOpen }, attributes);
                    } catch (error) {
                        console.error('Не вдалося відновити введені дані продукту.', error);
                    }
                    showEditMode();
                }
            }
        }
    }

    const clientBulkForm = document.getElementById('clientBulkForm');
    const startBulkDelete = document.getElementById('startBulkDelete');
    const bulkDeleteButton = document.getElementById('bulkDeleteButton');
    const cancelBulkDelete = document.getElementById('cancelBulkDelete');
    const selectAllClients = document.getElementById('selectAllClients');

    if (clientBulkForm && startBulkDelete && bulkDeleteButton && cancelBulkDelete && selectAllClients) {
        const clientCheckboxes = Array.from(clientBulkForm.querySelectorAll('.client-select-checkbox'));
        const setSelectionMode = (enabled) => {
            clientBulkForm.classList.toggle('bulk-selection-active', enabled);
            startBulkDelete.hidden = enabled;
            bulkDeleteButton.hidden = !enabled;
            cancelBulkDelete.hidden = !enabled;
        };

        const updateBulkSelection = () => {
            const selectedCount = clientCheckboxes.filter((checkbox) => checkbox.checked).length;
            bulkDeleteButton.disabled = selectedCount === 0;
            bulkDeleteButton.textContent = selectedCount > 0
                ? `Видалити вибраних (${selectedCount})`
                : 'Видалити вибраних';
            selectAllClients.checked = clientCheckboxes.length > 0 && selectedCount === clientCheckboxes.length;
            selectAllClients.indeterminate = selectedCount > 0 && selectedCount < clientCheckboxes.length;
        };

        startBulkDelete.addEventListener('click', () => {
            setSelectionMode(true);
            selectAllClients.focus();
        });

        cancelBulkDelete.addEventListener('click', () => {
            clientCheckboxes.forEach((checkbox) => {
                checkbox.checked = false;
            });
            selectAllClients.checked = false;
            selectAllClients.indeterminate = false;
            updateBulkSelection();
            setSelectionMode(false);
            startBulkDelete.focus();
        });

        clientCheckboxes.forEach((checkbox) => {
            checkbox.addEventListener('change', updateBulkSelection);
        });

        selectAllClients.addEventListener('change', () => {
            clientCheckboxes.forEach((checkbox) => {
                checkbox.checked = selectAllClients.checked;
            });
            updateBulkSelection();
        });

        clientBulkForm.addEventListener('submit', (event) => {
            const selectedCount = clientCheckboxes.filter((checkbox) => checkbox.checked).length;
            if (selectedCount === 0) {
                event.preventDefault();
                return;
            }

            if (!window.confirm(`Видалити вибраних клієнтів (${selectedCount})? Клієнти з пов’язаними замовленнями не будуть видалені.`)) {
                event.preventDefault();
            }
        });

        updateBulkSelection();
    }
//clientdetails
    const clientDetailsDialog = document.getElementById('clientDetailsDialog');
    const clientDetailsTitle = document.getElementById('clientDetailsTitle');
    const clientDetailsList = document.getElementById('clientDetailsList');
    const clientDetailsView = document.getElementById('clientDetailsView');
    const clientRelatedOrders = document.getElementById('clientRelatedOrders');
    const clientRelatedInquiries = document.getElementById('clientRelatedInquiries');
    const clientDetailsEdit = document.getElementById('clientDetailsEdit');
    const clientDetailsActions = document.getElementById('clientDetailsActions');
    const clientDetailsForm = document.getElementById('clientDetailsForm');
    const clientEditId = document.getElementById('clientEditId');
    const clientDeleteForm = document.getElementById('clientDeleteForm');
    const clientDeleteId = document.getElementById('clientDeleteId');
    let activeClientRow = null;

    if (clientDetailsDialog && clientDetailsTitle && clientDetailsList && clientDetailsView
        && clientRelatedOrders && clientRelatedInquiries && clientDetailsEdit && clientDetailsActions
        && clientDetailsForm && clientEditId && clientDeleteForm && clientDeleteId) {
        const editableFields = ['company_name', 'contact_person', 'phone', 'email', 'city', 'address', 'notes'];

        const setEditValues = (client) => {
            clientEditId.value = client.id || '';
            editableFields.forEach((field) => {
                const input = clientDetailsForm.elements.namedItem(field);
                if (input) {
                    input.value = client[field] || '';
                }
            });
        };

        const showEditMode = () => {
            clientDetailsView.hidden = true;
            clientDetailsActions.hidden = true;
            clientDetailsForm.hidden = false;
            clientDetailsForm.querySelector('[name="company_name"]').focus();
        };

        const showDetailsMode = () => {
            clientDetailsForm.hidden = true;
            clientDetailsView.hidden = false;
            clientDetailsActions.hidden = false;
        };

        const closeClientDetails = () => {
            clientDetailsDialog.hidden = true;
            showDetailsMode();
            if (activeClientRow) {
                activeClientRow.focus();
                activeClientRow = null;
            }
        };

        const detailFields = [
            ['id', 'Номер клієнта'],
            ['contact_person', 'Контактна особа'],
            ['phone', 'Телефон'],
            ['email', 'Електронна пошта'],
            ['city', 'Місто'],
            ['address', 'Адреса'],
            ['notes', 'Додаткова інформація'],
            ['created_by', 'ID автора запису'],
            ['created_by_name', 'Запис створив'],
            ['created_at', 'Дата створення'],
            ['updated_at', 'Останнє оновлення'],
        ];
        const renderRelatedRecords = (container, records, type) => {
            while (container.firstChild) {
                container.removeChild(container.firstChild);
            }
            if (!Array.isArray(records) || records.length === 0) {
                const emptyItem = document.createElement('li');
                emptyItem.className = 'client-related-empty';
                emptyItem.textContent = 'Немає пов’язаних записів.';
                container.appendChild(emptyItem);
                return;
            }
            records.forEach((record) => {
                const item = document.createElement('li');
                const link = document.createElement('a');
                const isOrder = type === 'order';
                const recordNumber = isOrder ? record.order_number : record.inquiry_number;
                const recordTitle = isOrder ? record.order_name : record.subject;
                link.href = `${isOrder ? 'orders.php' : 'inquiries.php'}?view=${encodeURIComponent(record.id)}`;
                link.textContent = `${recordNumber || (isOrder ? 'Замовлення' : 'Звернення')} · ${recordTitle || ''}`;
                item.appendChild(link);
                const metadata = document.createElement('span');
                const recordDate = isOrder ? record.order_date : (record.created_at || '').slice(0, 10);
                metadata.textContent = [recordDate, record.status].filter(Boolean).join(' · ');
                item.appendChild(metadata);
                container.appendChild(item);
            });
        };

        document.querySelectorAll('.client-row').forEach((row) => {
            const openDetails = () => {
                let client;
                try {
                    client = JSON.parse(row.dataset.clientDetails);
                } catch (error) {
                    console.error('Не вдалося прочитати дані клієнта.', error);
                    return;
                }

                clientDetailsTitle.textContent = client.company_name || 'Клієнт';
                setEditValues(client);
                clientDeleteId.value = client.id || '';
                while (clientDetailsList.firstChild) {
                    clientDetailsList.removeChild(clientDetailsList.firstChild);
                }

                detailFields.forEach(([field, label]) => {
                    const item = document.createElement('div');
                    item.className = 'client-details-item';

                    const term = document.createElement('dt');
                    term.textContent = label;

                    const value = document.createElement('dd');
                    value.textContent = client[field] || '—';

                    item.appendChild(term);
                    item.appendChild(value);
                    clientDetailsList.appendChild(item);
                });
                renderRelatedRecords(clientRelatedOrders, client.orders, 'order');
                renderRelatedRecords(clientRelatedInquiries, client.inquiries, 'inquiry');

                activeClientRow = row;
                clientDetailsDialog.hidden = false;
                clientDetailsDialog.querySelector('[role="dialog"]').focus();
                showDetailsMode();
                return client;
            };

            row.addEventListener('click', (event) => {
                if (event.target.closest('a, button, input, label')) {
                    return;
                }
                openDetails();
            });

            row.addEventListener('keydown', (event) => {
                if (event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) {
                    return;
                }
                event.preventDefault();
                openDetails();
            });
        });

        clientDetailsEdit.addEventListener('click', () => {
            showEditMode();
        });

        clientDetailsForm.querySelector('.client-details-cancel-edit').addEventListener('click', () => {
            showDetailsMode();
        });

        clientDeleteForm.addEventListener('submit', (event) => {
            if (!window.confirm(`Видалити клієнта «${clientDetailsTitle.textContent}»? Цю дію неможливо скасувати.`)) {
                event.preventDefault();
            }
        });

        clientDetailsDialog.querySelectorAll('.client-details-close').forEach((button) => {
            button.addEventListener('click', closeClientDetails);
        });

        clientDetailsDialog.addEventListener('click', (event) => {
            if (event.target === clientDetailsDialog) {
                closeClientDetails();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !clientDetailsDialog.hidden) {
                closeClientDetails();
            }
        });

        const reopenEdit = clientDetailsDialog.dataset.reopenEdit === '1';
        const clientIdToOpen = reopenEdit
            ? clientDetailsDialog.dataset.editClientId
            : clientDetailsDialog.dataset.viewClientId;

        if (clientIdToOpen) {
            const row = Array.from(document.querySelectorAll('.client-row'))
                .find((clientRow) => {
                    try {
                        return JSON.parse(clientRow.dataset.clientDetails).id === Number(clientIdToOpen);
                    } catch (error) {
                        console.error('Не вдалося прочитати дані клієнта.', error);
                        return false;
                    }
                });

            if (row) {
                openDetailsForRow(row);
            }
        }

        function openDetailsForRow(row) {
            row.click();
            if (reopenEdit) {
                try {
                    const formValues = JSON.parse(clientDetailsDialog.dataset.editValues);
                    setEditValues({ ...formValues, id: clientIdToOpen });
                } catch (error) {
                    console.error('Не вдалося відновити введені дані клієнта.', error);
                }
                showEditMode();
            }
        }
    }
});
