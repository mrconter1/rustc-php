// error: Cannot borrow immutable variable

fn main() {
    let x: i32 = 5;
    let p: &mut i32 = &mut x;
    *p = 10;
}
