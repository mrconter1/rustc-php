// error: *mut pointer requires &mut reference

fn main() {
    let x: i32 = 42;
    let p: *mut i32 = &x as *mut i32;
    *p = 0;
}
