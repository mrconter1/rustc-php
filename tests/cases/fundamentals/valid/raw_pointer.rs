// exit: 0
// stdout: 42
fn main() {
    let x: i32 = 42;
    let p: *const i32 = &x as *const i32;
    let v = *p;
    println!("{}", v);
}
