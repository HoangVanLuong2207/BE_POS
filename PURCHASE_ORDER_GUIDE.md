# Hướng dẫn sử dụng tính năng Hóa đơn nhập hàng và Import sản phẩm hàng loạt

## 🎯 Tổng quan

Hệ thống đã được bổ sung 2 tính năng chính:
1. **Quản lý hóa đơn nhập hàng** - Theo dõi việc nhập hàng từ nhà cung cấp
2. **Import sản phẩm hàng loạt** - Thêm nhiều sản phẩm cùng lúc

## 📋 Cấu trúc Database

### Bảng `purchase_orders` (Hóa đơn nhập hàng)
- `purchase_number`: Số hóa đơn (tự động tạo: PO202509100001)
- `supplier_name`: Tên nhà cung cấp
- `supplier_phone`: SĐT nhà cung cấp
- `supplier_address`: Địa chỉ nhà cung cấp
- `total_amount`: Tổng tiền nhập hàng
- `paid_amount`: Số tiền đã thanh toán
- `remaining_amount`: Số tiền còn nợ
- `payment_status`: Trạng thái thanh toán (pending/partial/paid)
- `status`: Trạng thái hóa đơn (draft/confirmed/completed/cancelled)
- `purchase_date`: Ngày nhập hàng
- `due_date`: Ngày đến hạn thanh toán

### Bảng `purchase_order_items` (Chi tiết hóa đơn nhập hàng)
- `purchase_order_id`: ID hóa đơn nhập hàng
- `product_id`: ID sản phẩm
- `product_name`: Tên sản phẩm (backup)
- `purchase_price`: Giá nhập
- `selling_price`: Giá bán
- `quantity`: Số lượng nhập
- `total_amount`: Thành tiền

## 🚀 API Endpoints

### 1. Quản lý Hóa đơn nhập hàng

#### Lấy danh sách hóa đơn nhập hàng
```http
GET /api/admin/purchase-orders
Query params:
- keyword: Tìm kiếm theo số hóa đơn hoặc tên nhà cung cấp
- status: Lọc theo trạng thái (draft/confirmed/completed/cancelled)
- payment_status: Lọc theo trạng thái thanh toán (pending/partial/paid)
- limit: Số lượng mỗi trang (mặc định: 10)
```

#### Tạo hóa đơn nhập hàng mới
```http
POST /api/admin/purchase-orders
Content-Type: application/json

{
  "supplier_name": "Nhà cung cấp ABC",
  "supplier_phone": "0123456789",
  "supplier_address": "123 Đường ABC, Quận 1, TP.HCM",
  "purchase_date": "2025-09-10",
  "due_date": "2025-09-20",
  "notes": "Ghi chú nhập hàng",
  "items": [
    {
      "product_id": 1,
      "purchase_price": 100,
      "selling_price": 150,
      "quantity": 50,
      "unit": "cái",
      "notes": "Ghi chú sản phẩm"
    }
  ]
}
```

#### Xem chi tiết hóa đơn
```http
GET /api/admin/purchase-orders/{id}
```

#### Cập nhật hóa đơn
```http
PUT /api/admin/purchase-orders/{id}
Content-Type: application/json

{
  "supplier_name": "Nhà cung cấp XYZ",
  "status": "completed",
  "paid_amount": 5000
}
```

#### Xóa hóa đơn
```http
DELETE /api/admin/purchase-orders/{id}
```

### 2. Import sản phẩm hàng loạt

#### Lấy danh sách sản phẩm để import
```http
GET /api/admin/purchase-orders/products/import
Query params:
- keyword: Tìm kiếm sản phẩm
- category_id: Lọc theo danh mục
- limit: Số lượng mỗi trang
```

#### Import sản phẩm hàng loạt
```http
POST /api/admin/products/bulk-import
Content-Type: application/json

{
  "products": [
    {
      "name": "Sản phẩm A",
      "description": "Mô tả sản phẩm A",
      "category_id": 1,
      "purchase_price": 100,
      "selling_price": 150,
      "quantity": 100,
      "unit": "cái",
      "active": true
    },
    {
      "name": "Sản phẩm B",
      "description": "Mô tả sản phẩm B",
      "category_id": 1,
      "purchase_price": 200,
      "selling_price": 300,
      "quantity": 50,
      "unit": "cái",
      "active": true
    }
  ]
}
```

## 📊 Dashboard mới

Dashboard đã được cập nhật với thông tin nhập hàng:

### Thống kê nhập hàng (`purchase_stats`)
- `total_amount`: Tổng tiền nhập hàng
- `paid_amount`: Số tiền đã thanh toán
- `remaining_amount`: Số tiền còn nợ
- `total_orders`: Tổng số hóa đơn
- `completed_orders`: Số hóa đơn đã hoàn thành
- `pending_orders`: Số hóa đơn chờ xử lý
- `payment_stats`: Thống kê theo trạng thái thanh toán
- `top_suppliers`: Top 5 nhà cung cấp

### Thống kê bán hàng (`sales_stats`)
- `total_amount`: Tổng tiền bán hàng
- `total_orders`: Tổng số đơn hàng
- `completed_orders`: Số đơn hàng đã hoàn thành
- `pending_orders`: Số đơn hàng chờ xử lý
- `payment_stats`: Thống kê theo trạng thái thanh toán

## 🧪 Testing

### Test tính năng cơ bản
```bash
php artisan test:purchase-order --create-sample
```

### Test API endpoints
```bash
# Lấy danh sách hóa đơn
curl -X GET "http://localhost:8000/api/admin/purchase-orders"

# Lấy dashboard với thống kê mới
curl -X GET "http://localhost:8000/api/admin/dashboard"
```

## 🔄 Luồng hoạt động

### 1. Tạo hóa đơn nhập hàng
1. Chọn sản phẩm từ danh sách có sẵn
2. Nhập thông tin nhà cung cấp
3. Nhập giá nhập, giá bán, số lượng cho từng sản phẩm
4. Hệ thống tự động:
   - Tạo số hóa đơn duy nhất
   - Tính tổng tiền
   - Cập nhật tồn kho sản phẩm
   - Cập nhật giá sản phẩm

### 2. Import sản phẩm hàng loạt
1. Chuẩn bị danh sách sản phẩm (JSON format)
2. Gọi API bulk import
3. Hệ thống tạo tất cả sản phẩm cùng lúc
4. Trả về kết quả thành công/lỗi cho từng sản phẩm

### 3. Quản lý thanh toán
1. Cập nhật `paid_amount` trong hóa đơn
2. Hệ thống tự động tính `remaining_amount`
3. Cập nhật `payment_status` (pending/partial/paid)

## ⚠️ Lưu ý quan trọng

1. **Xóa hóa đơn**: Chỉ có thể xóa hóa đơn chưa hoàn thành
2. **Cập nhật tồn kho**: Khi tạo hóa đơn nhập hàng, tồn kho sản phẩm sẽ tăng
3. **Xóa hóa đơn**: Tồn kho sẽ giảm tương ứng
4. **Số hóa đơn**: Tự động tạo theo format PO + YYYYMMDD + 4 số
5. **Validation**: Tất cả dữ liệu đều được validate nghiêm ngặt

## 🎉 Kết luận

Hệ thống đã được bổ sung đầy đủ tính năng quản lý nhập hàng và import sản phẩm hàng loạt, giúp:
- Quản lý nhập hàng chuyên nghiệp
- Theo dõi thanh toán với nhà cung cấp
- Import sản phẩm nhanh chóng
- Dashboard thống kê chi tiết
- Tự động cập nhật tồn kho và giá cả
