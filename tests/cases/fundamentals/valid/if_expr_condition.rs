// stdout: negative
fn main() {
    let n: i32 = 0 - 5;
    let label = if n > 0 { String::from("positive") } else { String::from("negative") };
    println!("{}", label);
}
