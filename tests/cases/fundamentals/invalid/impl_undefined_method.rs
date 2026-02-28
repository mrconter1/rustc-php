// error: Method 'nonexistent' not found

struct Bar {
    x: i32,
    y: i32,
}

fn main() {
    let b = Bar { x: 1, y: 2 };
    b.nonexistent();
}
